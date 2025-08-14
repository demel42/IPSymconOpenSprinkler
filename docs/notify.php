<?php

declare(strict_types=1);

$scriptName = IPS_GetName($_IPS['SELF']) . '(' . $_IPS['SELF'] . ')';

$instID = $_IPS['InstanceID'];
$message = $_IPS['message'];
$severity = $_IPS['severity']; // alert, warning

IPS_LogMessage($scriptName, $message);
