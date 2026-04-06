#!/usr/bin/env python3
import json
import re
import sys
import zipfile
import xml.etree.ElementTree as ET
from pathlib import PurePosixPath


NS = {
    "main": "http://schemas.openxmlformats.org/spreadsheetml/2006/main",
    "rel": "http://schemas.openxmlformats.org/officeDocument/2006/relationships",
    "pkgrel": "http://schemas.openxmlformats.org/package/2006/relationships",
}


def column_index_to_letter(index: int) -> str:
    letters = []
    while index > 0:
        index, remainder = divmod(index - 1, 26)
        letters.append(chr(65 + remainder))
    return "".join(reversed(letters))


def normalize_value(cell_type: str | None, raw_value: str | None, shared_strings: list[str]) -> object:
    if raw_value is None:
        return None

    value = raw_value.strip()
    if cell_type == "s":
        try:
            return shared_strings[int(value)]
        except (ValueError, IndexError):
            return value
    if cell_type == "b":
        return value == "1"
    if cell_type in {"str", "inlineStr"}:
        return value

    if re.fullmatch(r"-?\d+", value):
        try:
            return int(value)
        except ValueError:
            return value

    if re.fullmatch(r"-?\d+\.\d+", value):
        try:
            return float(value)
        except ValueError:
            return value

    return value


def parse_shared_strings(zf: zipfile.ZipFile) -> list[str]:
    try:
        data = zf.read("xl/sharedStrings.xml")
    except KeyError:
        return []

    root = ET.fromstring(data)
    values: list[str] = []
    for item in root.findall("main:si", NS):
        text_parts = [node.text or "" for node in item.findall(".//main:t", NS)]
        values.append("".join(text_parts))
    return values


def parse_workbook(zf: zipfile.ZipFile) -> list[tuple[str, str]]:
    workbook = ET.fromstring(zf.read("xl/workbook.xml"))
    rels = ET.fromstring(zf.read("xl/_rels/workbook.xml.rels"))
    rel_targets = {
        rel.attrib["Id"]: rel.attrib["Target"]
        for rel in rels.findall("pkgrel:Relationship", NS)
    }

    sheets: list[tuple[str, str]] = []
    for sheet in workbook.findall("main:sheets/main:sheet", NS):
        rel_id = sheet.attrib.get("{%s}id" % NS["rel"])
        target = rel_targets.get(rel_id or "")
        if not target:
            continue
        target_path = str(PurePosixPath("xl") / PurePosixPath(target))
        sheets.append((sheet.attrib.get("name", "Sheet"), target_path))
    return sheets


def parse_sheet(zf: zipfile.ZipFile, sheet_path: str, shared_strings: list[str]) -> list[dict[str, object]]:
    root = ET.fromstring(zf.read(sheet_path))
    rows: list[dict[str, object]] = []

    for row in root.findall("main:sheetData/main:row", NS):
        parsed_row: dict[str, object] = {}
        max_col = 0
        for cell in row.findall("main:c", NS):
            ref = cell.attrib.get("r", "")
            match = re.match(r"([A-Z]+)", ref)
            col = match.group(1) if match else ""
            if col:
                col_number = 0
                for char in col:
                    col_number = col_number * 26 + (ord(char) - 64)
                max_col = max(max_col, col_number)

            cell_type = cell.attrib.get("t")
            if cell_type == "inlineStr":
                text_nodes = cell.findall(".//main:t", NS)
                value = "".join(node.text or "" for node in text_nodes)
            else:
                value_node = cell.find("main:v", NS)
                value = value_node.text if value_node is not None else None

            parsed_row[col or column_index_to_letter(max_col)] = normalize_value(cell_type, value, shared_strings)

        if max_col > 0:
            dense_row = {column_index_to_letter(i): parsed_row.get(column_index_to_letter(i)) for i in range(1, max_col + 1)}
            rows.append(dense_row)
        else:
            rows.append(parsed_row)

    return rows


def main() -> int:
    if len(sys.argv) != 2:
        print("Usage: read_xlsx_rows.py <path>", file=sys.stderr)
        return 1

    path = sys.argv[1]

    with zipfile.ZipFile(path) as zf:
        shared_strings = parse_shared_strings(zf)
        worksheets = []
        for title, sheet_path in parse_workbook(zf):
            worksheets.append({
                "title": title,
                "rows": parse_sheet(zf, sheet_path, shared_strings),
            })

    print(json.dumps(worksheets, ensure_ascii=False))
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
