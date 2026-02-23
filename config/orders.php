<?php

return [
    'prazo_padrao_dias_uteis' => (int) env('PEDIDOS_PRAZO_PADRAO_DIAS_UTEIS', 60),
    'nfe_xml_max_kb' => (int) env('PEDIDOS_NFE_XML_MAX_KB', 2048),
];
