<?php
namespace App\Http\Controllers;

use App\Models\Feriado;
use App\Services\Holidays\HolidaySyncService;
use Illuminate\Http\Request;
use Illuminate\Http\JsonResponse;

class FeriadoController extends Controller
{
    /** GET /feriados?ano=2025&uf=PA&escopo=nacional|estadual */
    public function index(Request $req): JsonResponse
    {
        $ano = (int)($req->query('ano') ?? now('America/Belem')->year);
        $uf  = $req->query('uf');
        $esc = $req->query('escopo'); // opcional

        $q = Feriado::query()->ano($ano)->uf($uf);
        if ($esc) $q->where('escopo', $esc);

        $data = $q->orderBy('data')->get();

        return response()->json(['data' => $data]);
    }

    /** POST /feriados/sync { year?:int, uf?:string, only?:string } */
    public function sync(Request $req, HolidaySyncService $svc): JsonResponse
    {
        $year = (int)($req->input('year') ?? now('America/Belem')->year);
        $uf   = $req->input('uf', config('holidays.default_uf', 'PA'));
        $only = $req->input('only', 'all');

        $total = 0;
        if ($only === 'all' || $only === 'nacionais') $total += $svc->syncNacionais($year);
        if ($only === 'all' || $only === 'estaduais') $total += $svc->syncEstaduais($year, $uf);

        return response()->json(['message' => 'Sincronizado', 'total' => $total]);
    }
}
