<?php
require "C:/xampp/htdocs/kofc/api/config.php";
$k = ANTHROPIC_API_KEY;
echo "len=" . strlen($k) . " starts=" . substr($k, 0, 7) . PHP_EOL;
echo "mock=" . var_export(AI_MOCK, true) . PHP_EOL;
