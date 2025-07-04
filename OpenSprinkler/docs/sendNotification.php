<?php

declare(strict_types=1);

$scriptName = IPS_GetName($_IPS['SELF']) . '(' . $_IPS['SELF'] . ')';
$scriptInfo = IPS_GetName(IPS_GetParent($_IPS['SELF'])) . '\\' . IPS_GetName($_IPS['SELF']);

$subject = 'Bewässerung';
$severity = $_IPS['severity'];
$message = $_IPS['message'];

$type = $_IPS['type'];
if ($type == 'flow') {
    $station = $_IPS['station'];
    $flow_rate = $_IPS['flow_rate'];
    $alert_setpoint = $_IPS['alert_setpoint'];
    $percent = floor($flow_rate / $alert_setpoint * 100);

    $message = '"' . $station . '" hat erhöhten Wasserfluss von ' . $flow_rate . ' l/min (' . $percent . '%)';
}

IPS_LogMessages($scriptName, $message);
