<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/common.php';
require_once __DIR__ . '/../libs/local.php';

class OpenSprinkler extends IPSModule
{
    use OpenSprinkler\StubsCommonLib;
    use OpenSprinklerLocalLib;

    public static $MAX_INT_SENSORS = 2;
    public static $ADHOC_PROGRAM = 254;
    public static $MANUAL_STATION_START = 99;

    public static $SENSOR_PREFIX = 'SN';
    public static $STATION_PREFIX = 'S';
    public static $PROGRAM_PREFIX = 'P';

    public static $PRECISION_FLOW = 2;
    public static $PRECISION_USAGE = 2;

    private $VarProf_Stations;
    private $VarProf_Programs;
    private $VarProf_PauseQueueAction;
    private $VarProf_StationStartManually;

    private static $semaphoreTM = 5 * 1000;

    private $SemaphoreID;

    public function __construct(string $InstanceID)
    {
        parent::__construct($InstanceID);

        $this->CommonConstruct(__DIR__);
        $this->SemaphoreID = __CLASS__ . '_' . $InstanceID;

        $this->VarProf_Stations = 'OpenSprinkler.Stations_' . $this->InstanceID;
        $this->VarProf_Programs = 'OpenSprinkler.Programs_' . $this->InstanceID;
        $this->VarProf_PauseQueueAction = 'OpenSprinkler.PauseQueueAction_' . $this->InstanceID;
        $this->VarProf_StationStartManually = 'OpenSprinkler.StationStartManually_' . $this->InstanceID;
    }

    public function __destruct()
    {
        $this->CommonDestruct();
    }

    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyBoolean('module_disable', false);

        $this->RegisterPropertyString('host', '');
        $this->RegisterPropertyBoolean('use_https', false);
        $this->RegisterPropertyInteger('port', 0);
        $this->RegisterPropertyString('password', '');

        $this->RegisterPropertyInteger('query_interval', 60);

        $this->RegisterPropertyString('mqtt_topic', 'opensprinkler');

        $this->RegisterPropertyString('station_list', json_encode([]));
        $this->RegisterPropertyString('sensor_list', json_encode([]));
        $this->RegisterPropertyString('program_list', json_encode([]));

        $this->RegisterPropertyString('variables_mqtt_topic', 'opensprinkler/variables');
        $this->RegisterPropertyString('variable_list', json_encode([]));
        $this->RegisterPropertyInteger('send_interval', 300);

        $this->RegisterPropertyBoolean('with_controller_daily_duration', false);
        $this->RegisterPropertyBoolean('with_controller_daily_usage', false);
        $this->RegisterPropertyBoolean('with_controller_total_duration', true);
        $this->RegisterPropertyBoolean('with_controller_total_usage', true);

        $this->RegisterPropertyBoolean('with_station_daily_duration', false);
        $this->RegisterPropertyBoolean('with_station_daily_usage', true);
        $this->RegisterPropertyBoolean('with_station_total_duration', false);
        $this->RegisterPropertyBoolean('with_station_total_usage', false);
        $this->RegisterPropertyBoolean('with_station_last_run', true);
        $this->RegisterPropertyBoolean('with_station_next_run', false);
        $this->RegisterPropertyBoolean('with_station_usage', true);
        $this->RegisterPropertyBoolean('with_station_flow', true);

        $this->RegisterPropertyBoolean('with_summary', false);
        $this->RegisterPropertyInteger('summary_scriptID', 0);

        $this->RegisterPropertyInteger('log_max_age', 90);

        $this->RegisterPropertyInteger('notification_scriptID', 1);

        $this->RegisterPropertyInteger('WaterMeterID', 0);
        $this->RegisterPropertyFloat('WaterMeterFactor', 1);

        $this->RegisterAttributeString('controller_infos', json_encode([]));
        $this->RegisterAttributeString('station_infos', json_encode([]));
        $this->RegisterAttributeString('program_infos', json_encode([]));

        $this->RegisterAttributeString('cur_runs', json_encode([]));
        $this->RegisterAttributeInteger('daily_reference', 0);

        $this->RegisterAttributeString('UpdateInfo', json_encode([]));
        $this->RegisterAttributeString('ModuleStats', json_encode([]));

        $this->InstallVarProfiles(false);

        $this->CreateVarProfile($this->VarProf_Stations, VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', [['Wert' => 0, 'Name' => '-']], false);
        $this->CreateVarProfile($this->VarProf_Programs, VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', [['Wert' => 0, 'Name' => '-']], false);
        $this->CreateVarProfile($this->VarProf_PauseQueueAction, VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', [['Wert' => 0, 'Name' => $this->Translate('Set')]], false);
        $this->CreateVarProfile($this->VarProf_StationStartManually, VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', [['Wert' => 0, 'Name' => $this->Translate('Set')]], false);

        $this->RegisterTimer('QueryStatus', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "QueryStatus", "");');
        $this->RegisterTimer('SendVariables', 0, 'IPS_RequestAction(' . $this->InstanceID . ', "SendVariables", "");');

        $this->RegisterMessage(0, IPS_KERNELMESSAGE);

        $this->ConnectParent('{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}');
    }

    public function Destroy()
    {
        if (IPS_InstanceExists($this->InstanceID) == false) {
            $idents = [
                $this->VarProf_Stations,
                $this->VarProf_Programs,
                $this->VarProf_PauseQueueAction,
                $this->VarProf_StationStartManually,
            ];
            foreach ($idents as $ident) {
                if (IPS_VariableProfileExists($ident)) {
                    IPS_DeleteVariableProfile($ident);
                }
            }
        }
        parent::Destroy();
    }

    public function MessageSink($timestamp, $senderID, $message, $data)
    {
        parent::MessageSink($timestamp, $senderID, $message, $data);

        if ($message == IPS_KERNELMESSAGE && $data[0] == KR_READY) {
            $this->SetQueryInterval();
            $this->SetSendInterval();
        }

        if (IPS_GetKernelRunlevel() == KR_READY && $message == VM_UPDATE && $data[1] == true /* changed */) {
            $this->SendDebug(__FUNCTION__, 'timestamp=' . $timestamp . ', senderID=' . $senderID . ', message=' . $message . ', data=' . print_r($data, true), 0);
        }
    }

    private function CheckModuleConfiguration()
    {
        $r = [];

        $host = $this->ReadPropertyString('host');
        if ($host == '') {
            $this->SendDebug(__FUNCTION__, '"host" is needed', 0);
            $r[] = $this->Translate('Host must be specified');
        }

        $password = $this->ReadPropertyString('password');
        if ($password == '') {
            $this->SendDebug(__FUNCTION__, '"password" is needed', 0);
            $r[] = $this->Translate('Password must be specified');
        }

        return $r;
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();

        $propertyNames = [
            'summary_scriptID',
            'notification_scriptID',
            'WaterMeterID',
        ];
        $this->MaintainReferences($propertyNames);

        $varIDs = [];
        $variable_list = (array) @json_decode($this->ReadPropertyString('variable_list'), true);
        foreach ($variable_list as $variable) {
            $varID = $variable['varID'];
            if ($this->IsValidID($varID) && IPS_VariableExists($varID)) {
                $this->RegisterReference($varID);
                $varIDs[] = $varID;
            }
        }

        if ($this->CheckPrerequisites() != false) {
            $this->MaintainTimer('QueryStatus', 0);
            $this->MaintainTimer('SendVariables', 0);
            $this->MaintainStatus(self::$IS_INVALIDPREREQUISITES);
            return;
        }

        if ($this->CheckUpdate() != false) {
            $this->MaintainTimer('QueryStatus', 0);
            $this->MaintainTimer('SendVariables', 0);
            $this->MaintainStatus(self::$IS_UPDATEUNCOMPLETED);
            return;
        }

        if ($this->CheckConfiguration() != false) {
            $this->MaintainTimer('QueryStatus', 0);
            $this->MaintainTimer('SendVariables', 0);
            $this->MaintainStatus(self::$IS_INVALIDCONFIG);
            return;
        }

        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);
        $station_infos = (array) @json_decode($this->ReadAttributeString('station_infos'), true);
        $program_infos = (array) @json_decode($this->ReadAttributeString('program_infos'), true);

        $station_list = (array) @json_decode($this->ReadPropertyString('station_list'), true);
        $sensor_list = (array) @json_decode($this->ReadPropertyString('sensor_list'), true);
        $program_list = (array) @json_decode($this->ReadPropertyString('program_list'), true);

        $sensor_type1 = $this->GetArrayElem($controller_infos, 'sensor_type.1', self::$SENSOR_TYPE_NONE);
        $sensor_type2 = $this->GetArrayElem($controller_infos, 'sensor_type.2', self::$SENSOR_TYPE_NONE);

        $varList = [];

        // 1..100: Controller
        $vpos = 1;
        $u = $this->Use4Ident('ControllerEnabled');
        $e = $this->Enable4Ident('ControllerEnabled');
        $this->MaintainVariable('ControllerEnabled', $this->Translate('Controller is enabled'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('ControllerEnabled', $e);
        }

        $vpos = 10;

        $u = $this->Use4Ident('DeviceTime');
        $this->MaintainVariable('DeviceTime', $this->Translate('Device time'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $u);
        $u = $this->Use4Ident('WifiStrength');
        $this->MaintainVariable('WifiStrength', $this->Translate('Wifi signal strenght'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Wifi', $vpos++, $u);

        $u = $this->Use4Ident('LastRebootTstamp');
        $this->MaintainVariable('LastRebootTstamp', $this->Translate('Timestamp of last reboot'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $u);
        $u = $this->Use4Ident('LastRebootCause');
        $this->MaintainVariable('LastRebootCause', $this->Translate('Cause of last reboot'), VARIABLETYPE_INTEGER, 'OpenSprinkler.RebootCause', $vpos++, $u);

        $this->MaintainVariable('LastUpdate', $this->Translate('Last update'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, true);

        $u = $this->Use4Ident('WeatherQueryTstamp');
        $this->MaintainVariable('WeatherQueryTstamp', $this->Translate('Timestamp of last weather information'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $u);
        $u = $this->Use4Ident('WeatherQueryStatus');
        $this->MaintainVariable('WeatherQueryStatus', $this->Translate('Status of last weather query'), VARIABLETYPE_INTEGER, 'OpenSprinkler.WeatherQueryStatus', $vpos++, $u);

        $vpos = 50;
        $u = $this->Use4Ident('WateringLevel');
        $e = $this->Enable4Ident('WateringLevel');
        $this->MaintainVariable('WateringLevel', $this->Translate('Watering level'), VARIABLETYPE_INTEGER, 'OpenSprinkler.WateringLevel', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('WateringLevel', $e);
        }

        $vpos = 101;
        $u = $this->Use4Ident('CurrentDraw');
        $this->MaintainVariable('CurrentDraw', $this->Translate('Current draw (actual)'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Current', $vpos++, $u);

        $vpos = 111;
        $u = $this->Use4Ident('WaterFlowrate');
        $this->MaintainVariable('WaterFlowrate', $this->Translate('Water flow rate (actual)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowrate', $vpos++, $u);
        $varList[] = 'WaterFlowrate';

        $u = $this->Use4Ident('DailyWaterUsage');
        $this->MaintainVariable('DailyWaterUsage', $this->Translate('Water usage (today)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowmeter', $vpos++, $u);
        $varList[] = 'DailyWaterUsage';

        $u = $this->Use4Ident('TotalWaterUsage');
        @$varID = $this->GetIDForIdent('TotalWaterUsage');
        $this->MaintainVariable('TotalWaterUsage', $this->Translate('Water usage (total)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowmeter', $vpos++, $u);
        $varList[] = 'TotalWaterUsage';
        if ($u && @$varID == false) {
            $this->SetVariableLogging('TotalWaterUsage', 1 /* Zähler */);
        }

        $vpos = 121;
        $u = $this->Use4Ident('DailyDuration');
        $this->MaintainVariable('DailyDuration', $this->Translate('Watering time (today)'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);
        $varList[] = 'DailyDuration';

        $u = $this->Use4Ident('TotalDuration');
        @$varID = $this->GetIDForIdent('TotalDuration');
        $this->MaintainVariable('TotalDuration', $this->Translate('Watering time (total)'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);
        $varList[] = 'TotalDuration';
        if ($u && @$varID == false) {
            $this->SetVariableLogging('TotalDuration', 1 /* Zähler */);
        }

        $vpos = 151;
        $u = $this->Use4Ident('Summary');
        $e = $this->Enable4Ident('Summary');
        $this->MaintainVariable('Summary', $this->Translate('Summary of irrigation'), VARIABLETYPE_STRING, '~HTMLBox', $vpos++, $u);
        $this->MaintainVariable('SummaryDays', $this->Translate('Number of previous days in summary'), VARIABLETYPE_INTEGER, 'OpenSprinkler.SummaryDays', $vpos++, $u);
        $this->MaintainVariable('SummaryGroupBy', $this->Translate('Group by of summary'), VARIABLETYPE_INTEGER, 'OpenSprinkler.SummaryGroupBy', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('SummaryDays', $e);
            $this->MaintainAction('SummaryGroupBy', $e);
        }

        $vpos = 201;
        $u = $this->Use4Ident('SensorState_1');
        $this->MaintainVariable('SensorState_1', self::$SENSOR_PREFIX . '1: ' . $this->SensorType2String($sensor_type1), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.SensorState', $vpos++, $u);
        $varList[] = 'SensorState_1';

        $vpos = 211;
        $u = $this->Use4Ident('SensorState_2');
        $this->MaintainVariable('SensorState_2', self::$SENSOR_PREFIX . '2: ' . $this->SensorType2String($sensor_type2), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.SensorState', $vpos++, $u);
        $varList[] = 'SensorState_2';

        $vpos = 301;
        $u = $this->Use4Ident('RainDelay');
        $e = $this->Enable4Ident('RainDelay');
        $this->MaintainVariable('RainDelayUntil', $this->Translate('Rain delay until'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $u);
        $this->MaintainVariable('RainDelayDays', $this->Translate('Rain delay duration in days'), VARIABLETYPE_INTEGER, 'OpenSprinkler.RainDelayDays', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('RainDelayDays', $e);
        }
        $this->MaintainVariable('RainDelayHours', $this->Translate('Rain delay duration in hours'), VARIABLETYPE_INTEGER, 'OpenSprinkler.RainDelayHours', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('RainDelayHours', $e);
        }
        $this->MaintainVariable('RainDelayAction', $this->Translate('Rain delay action'), VARIABLETYPE_INTEGER, 'OpenSprinkler.RainDelayAction', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('RainDelayAction', $e);
        }

        $vpos = 601;
        $u = $this->Use4Ident('StopAllStations');
        $e = $this->Enable4Ident('StopAllStations');
        $this->MaintainVariable('StopAllStations', $this->Translate('Stop all stations'), VARIABLETYPE_INTEGER, 'OpenSprinkler.StopAllStations', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StopAllStations', $e);
        }

        $vpos = 701;
        $u = $this->Use4Ident('PauseQueue');
        $e = $this->Enable4Ident('PauseQueue');
        $this->MaintainVariable('PauseQueueUntil', $this->Translate('Pause queue until'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $u);
        $this->MaintainVariable('PauseQueueHours', $this->Translate('Pause queue duration in hours'), VARIABLETYPE_INTEGER, 'OpenSprinkler.PauseQueueHours', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('PauseQueueHours', $e);
        }
        $this->MaintainVariable('PauseQueueMinutes', $this->Translate('Pause queue duration in minutes'), VARIABLETYPE_INTEGER, 'OpenSprinkler.PauseQueueMinutes', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('PauseQueueMinutes', $e);
        }
        $this->MaintainVariable('PauseQueueSeconds', $this->Translate('Pause queue duration in seconds'), VARIABLETYPE_INTEGER, 'OpenSprinkler.PauseQueueSeconds', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('PauseQueueSeconds', $e);
        }
        $this->MaintainVariable('PauseQueueAction', $this->Translate('Pause queue action'), VARIABLETYPE_INTEGER, $this->VarProf_PauseQueueAction, $vpos++, $u);
        if ($u) {
            $this->MaintainAction('PauseQueueAction', $e);
        }

        $vpos = 801;
        $u = $this->Use4Ident('StationSelection');
        $e = $this->Enable4Ident('StationSelection');
        $this->MaintainVariable('StationSelection', $this->Translate('Station selection'), VARIABLETYPE_INTEGER, $this->VarProf_Stations, $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationSelection', $e);
        }

        $u = $this->Use4Ident('StationState');
        $this->MaintainVariable('StationState', $this->Translate('Station state'), VARIABLETYPE_INTEGER, 'OpenSprinkler.StationState', $vpos++, $u);

        $u = $this->Use4Ident('StationDisabled');
        $e = $this->Enable4Ident('StationDisabled');
        $this->MaintainVariable('StationDisabled', $this->Translate('Station is disabled'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationDisabled', $e);
        }

        $u = $this->Use4Ident('StationIgnoreRain');
        $e = $this->Enable4Ident('StationIgnoreRain');
        $this->MaintainVariable('StationIgnoreRain', $this->Translate('Station ignores rain delay'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationIgnoreRain', $e);
        }

        $u = $this->Use4Ident('StationIgnoreSensor1');
        $e = $this->Enable4Ident('StationIgnoreSensor1');
        $this->MaintainVariable('StationIgnoreSensor1', $this->Translate('Station ignores sensor 1'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationIgnoreSensor1', $e);
        }

        $u = $this->Use4Ident('StationIgnoreSensor2');
        $e = $this->Enable4Ident('StationIgnoreSensor2');
        $this->MaintainVariable('StationIgnoreSensor2', $this->Translate('Station ignores sensor 2'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationIgnoreSensor2', $e);
        }

        $u = $this->Use4Ident('StationTimeLeft');
        $this->MaintainVariable('StationTimeLeft', $this->Translate('Time left'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);
        $u = $this->Use4Ident('StationLastRun');
        $this->MaintainVariable('StationLastRun', $this->Translate('Last run'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $u);
        $u = $this->Use4Ident('StationLastDuration');
        $this->MaintainVariable('StationLastDuration', $this->Translate('Duration of last run'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);
        $u = $this->Use4Ident('StationWaterUsage');
        $this->MaintainVariable('StationWaterUsage', $this->Translate('Water usage of last run'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowmeter', $vpos++, $u);
        $u = $this->Use4Ident('StationNextRun');
        $this->MaintainVariable('StationNextRun', $this->Translate('Next run'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $u);
        $u = $this->Use4Ident('StationNextDuration');
        $this->MaintainVariable('StationNextDuration', $this->Translate('Duration of next run'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);

        $u = $this->Use4Ident('StationFlowAverage');
        $this->MaintainVariable('StationFlowAverage', $this->Translate('Average water flow'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowrate', $vpos++, $u);

        $u = $this->Use4Ident('StationFlowThreshold');
        $e = $this->Enable4Ident('StationFlowThreshold');
        $this->MaintainVariable('StationFlowThreshold', $this->Translate('Water flow monitoring threshold'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowrate', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationFlowThreshold', $e);
        }

        // Tageswerte
        $u = $this->Use4Ident('StationDailyWaterUsage');
        $this->MaintainVariable('StationDailyWaterUsage', $this->Translate('Water usage (today)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowmeter', $vpos++, $u);
        $u = $this->Use4Ident('StationDailyDuration');
        $this->MaintainVariable('StationDailyDuration', $this->Translate('Watering time (today)'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);

        $u = $this->Use4Ident('StationStartManually');
        $e = $this->Enable4Ident('StationStartManually');
        $this->MaintainVariable('StationStartManuallyHours', $this->Translate('Station run duration in hours'), VARIABLETYPE_INTEGER, 'OpenSprinkler.StationStartManuallyHours', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationStartManuallyHours', $e);
        }
        $this->MaintainVariable('StationStartManuallyMinutes', $this->Translate('Station run duration in minutes'), VARIABLETYPE_INTEGER, 'OpenSprinkler.StationStartManuallyMinutes', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationStartManuallyMinutes', $e);
        }
        $this->MaintainVariable('StationStartManuallySeconds', $this->Translate('Station run duration in seconds'), VARIABLETYPE_INTEGER, 'OpenSprinkler.StationStartManuallySeconds', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationStartManuallySeconds', $e);
        }
        $this->MaintainVariable('StationStartManually', $this->Translate('Station start manually'), VARIABLETYPE_INTEGER, $this->VarProf_StationStartManually, $vpos++, $u);
        if ($u) {
            $this->MaintainAction('StationStartManually', $e);
        }

        $u = $this->Use4Ident('StationInfo');
        $this->MaintainVariable('StationInfo', $this->Translate('Station information'), VARIABLETYPE_STRING, '~TextBox', $vpos++, $u);
        $u = $this->Use4Ident('StationRunning');
        $this->MaintainVariable('StationRunning', $this->Translate('Station current running'), VARIABLETYPE_STRING, '~TextBox', $vpos++, $u);
        $u = $this->Use4Ident('StationLast');
        $this->MaintainVariable('StationLast', $this->Translate('Station last running'), VARIABLETYPE_STRING, '~TextBox', $vpos++, $u);

        $u = $this->Use4Ident('StationSummary');
        $this->MaintainVariable('StationSummary', $this->Translate('Summary of irrigation'), VARIABLETYPE_STRING, '~HTMLBox', $vpos++, $u);

        // 1001..8299: Stations (max 72)
        for ($station_n = 0; $station_n < count($station_list); $station_n++) {
            $station_entry = $station_list[$station_n];

            $station_info = [];
            for ($i = 0; $i < count($station_infos); $i++) {
                if ($station_infos[$i]['sid'] == $station_n) {
                    $station_info = $station_infos[$i];
                    break;
                }
            }

            $vpos = 1000 + $station_n * 100 + 1;
            $post = '_' . ($station_n + 1);
            $s = sprintf(self::$STATION_PREFIX . '%02d[%s]: ', $station_n + 1, $station_entry['name']);

            $u = $station_entry['use'] && $this->Use4Ident('StationState', $station_n);
            $this->MaintainVariable('StationState' . $post, $s . $this->Translate('Station state'), VARIABLETYPE_INTEGER, 'OpenSprinkler.StationState', $vpos++, $u);
            $varList[] = 'StationState' . $post;

            // aktueller Bewässerungszyklus
            $u = $station_entry['use'] && $this->Use4Ident('StationTimeLeft', $station_n);
            $this->MaintainVariable('StationTimeLeft' . $post, $s . $this->Translate('Time left'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);
            $varList[] = 'StationTimeLeft' . $post;

            // letzter Bewässerungszyklus
            $u = $station_entry['use'] && $this->Use4Ident('StationLastRun', $station_n);
            $this->MaintainVariable('StationLastRun' . $post, $s . $this->Translate('Last run'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $u);
            $varList[] = 'StationLastRun' . $post;
            $u = $station_entry['use'] && $this->Use4Ident('StationLastDuration', $station_n);
            $this->MaintainVariable('StationLastDuration' . $post, $s . $this->Translate('Duration of last run'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);
            $varList[] = 'StationLastDuration' . $post;
            $u = $station_entry['use'] && $this->Use4Ident('StationWaterUsage', $station_n);
            $this->MaintainVariable('StationWaterUsage' . $post, $s . $this->Translate('Water usage of last run'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowmeter', $vpos++, $u);
            $varList[] = 'StationWaterUsage' . $post;

            // nächster Bewässerungszyklus
            $u = $station_entry['use'] && $this->Use4Ident('StationNextRun', $station_n);
            $this->MaintainVariable('StationNextRun' . $post, $s . $this->Translate('Next run'), VARIABLETYPE_INTEGER, '~UnixTimestamp', $vpos++, $u);
            $varList[] = 'StationNextRun' . $post;
            $u = $station_entry['use'] && $this->Use4Ident('StationNextDuration', $station_n);
            $this->MaintainVariable('StationNextDuration' . $post, $s . $this->Translate('Duration of next run'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);
            $varList[] = 'StationNextDuration' . $post;

            // Strömungsüberwachung
            $u = $station_entry['use'] && $this->Use4Ident('StationFlowAverage', $station_n);
            $this->MaintainVariable('StationFlowAverage' . $post, $s . $this->Translate('Average water flow'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowrate', $vpos++, $u);
            $varList[] = 'StationFlowAverage' . $post;

            // Tagessummen
            $u = $station_entry['use'] && $this->Use4Ident('StationDailyWaterUsage', $station_n);
            $this->MaintainVariable('StationDailyWaterUsage' . $post, $s . $this->Translate('Water usage (today)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowmeter', $vpos++, $u);
            $varList[] = 'StationDailyWaterUsage' . $post;
            $u = $station_entry['use'] && $this->Use4Ident('StationDailyDuration', $station_n);
            $this->MaintainVariable('StationDailyDuration' . $post, $s . $this->Translate('Watering time (today)'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);
            $varList[] = 'StationDailyDuration' . $post;

            // Gesamtsummen
            $u = $station_entry['use'] && $this->Use4Ident('StationTotalWaterUsage', $station_n);
            @$varID = $this->GetIDForIdent('StationTotalWaterUsage' . $post);
            $this->MaintainVariable('StationTotalWaterUsage' . $post, $s . $this->Translate('Water usage (total)'), VARIABLETYPE_FLOAT, 'OpenSprinkler.WaterFlowmeter', $vpos++, $u);
            $varList[] = 'StationTotalWaterUsage' . $post;
            if ($u && @$varID == false) {
                $this->SetVariableLogging('StationTotalWaterUsage' . $post, 1 /* Zähler */);
            }
            $u = $station_entry['use'] && $this->Use4Ident('StationTotalDuration', $station_n);
            @$varID = $this->GetIDForIdent('StationTotalDuration' . $post);
            $this->MaintainVariable('StationTotalDuration' . $post, $s . $this->Translate('Watering time (total)'), VARIABLETYPE_INTEGER, 'OpenSprinkler.Duration', $vpos++, $u);
            $varList[] = 'StationTotalDuration' . $post;
            if ($u && @$varID == false) {
                $this->SetVariableLogging('StationTotalDuration' . $post, 1 /* Zähler */);
            }
        }

        $vpos = 900;
        $u = $this->Use4Ident('ProgramSelection');
        $e = $this->Enable4Ident('ProgramSelection');
        $this->MaintainVariable('ProgramSelection', $this->Translate('Program selection'), VARIABLETYPE_INTEGER, $this->VarProf_Programs, $vpos++, $u);
        if ($u) {
            $this->MaintainAction('ProgramSelection', $e);
        }
        $u = $this->Use4Ident('ProgramEnabled');
        $e = $this->Enable4Ident('ProgramEnabled');
        $this->MaintainVariable('ProgramEnabled', $this->Translate('Program is enabled'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('ProgramEnabled', $e);
        }
        $u = $this->Use4Ident('ProgramWeatherAdjust');
        $e = $this->Enable4Ident('ProgramWeatherAdjust');
        $this->MaintainVariable('ProgramWeatherAdjust', $this->Translate('Program with weather adjustments'), VARIABLETYPE_BOOLEAN, 'OpenSprinkler.YesNo', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('ProgramWeatherAdjust', $e);
        }
        $u = $this->Use4Ident('ProgramStartManually');
        $e = $this->Enable4Ident('ProgramStartManually');
        $this->MaintainVariable('ProgramStartManually', $this->Translate('Program start manually'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ProgramStart', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('ProgramStartManually', $e);
        }
        $u = $this->Use4Ident('ProgramInfo');
        $this->MaintainVariable('ProgramInfo', $this->Translate('Program information'), VARIABLETYPE_STRING, '~TextBox', $vpos++, $u);
        $u = $this->Use4Ident('ProgramRunning');
        $this->MaintainVariable('ProgramRunning', $this->Translate('Program current running'), VARIABLETYPE_STRING, '~TextBox', $vpos++, $u);
        $u = $this->Use4Ident('ProgramLast');
        $this->MaintainVariable('ProgramLast', $this->Translate('Program last running'), VARIABLETYPE_STRING, '~TextBox', $vpos++, $u);

        $vpos = 950;
        /*
        $u = $this->Use4Ident('ProgramDayRestriction');
        $e = $this->Enable4Ident('ProgramDayRestriction');
        $this->MaintainVariable('ProgramDayRestriction', $this->Translate('Program day restriction'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ProgramDayRestriction', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('ProgramDayRestriction', $e);
        }

        $u = $this->Use4Ident('ProgramScheduleType');
        $e = $this->Enable4Ident('ProgramScheduleType');
        $this->MaintainVariable('ProgramScheduleType', $this->Translate('Program schedule type'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ProgramScheduleType', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('ProgramScheduleType', $e);
        }

        $u = $this->Use4Ident('ProgramStarttimeType');
        $e = $this->Enable4Ident('ProgramStarttimeType');
        $this->MaintainVariable('ProgramStarttimeType', $this->Translate('Program starttime type'), VARIABLETYPE_INTEGER, 'OpenSprinkler.ProgramStarttimeType', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('ProgramStarttimeType', $e);
        }

        $u = $this->Use4Ident('IrrigationDurationHours');
        $this->MaintainVariable('IrrigationDurationHours', $this->Translate('Irrigation duration in hours'), VARIABLETYPE_INTEGER, 'OpenSprinkler.IrrigationDurationHours', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('IrrigationDurationHours', $e);
        }

        $u = $this->Use4Ident('IrrigationDurationMinutes');
        $this->MaintainVariable('IrrigationDurationMinutes', $this->Translate('Irrigation duration in minutes'), VARIABLETYPE_INTEGER, 'OpenSprinkler.IrrigationDurationMinutes', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('IrrigationDurationMinutes', $e);
        }

        $u = $this->Use4Ident('IrrigationDurationSeconds');
        $this->MaintainVariable('IrrigationDurationSeconds', $this->Translate('Irrigation duration in seconds'), VARIABLETYPE_INTEGER, 'OpenSprinkler.IrrigationDurationSeconds', $vpos++, $u);
        if ($u) {
            $this->MaintainAction('IrrigationDurationSeconds', $e);
        }

        $u = $this->Use4Ident('IrrigationDuration');
        $e = $this->Enable4Ident('IrrigationDuration');
        $this->MaintainVariable('IrrigationDuration', $this->Translate('Irrigation duration'), VARIABLETYPE_STRING, '', $vpos++, $u);
        if ($e) {
            $this->MaintainAction('IrrigationDuration', true);
        }
         */

        // 10001..14999: Programs (max 40)
        for ($program_n = 0; $program_n < count($program_list); $program_n++) {
            $program_entry = $program_list[$program_n];

            $vpos = 20000 + $program_n * 100 + 1;
            $post = '_' . ($program_n + 1);
            $s = sprintf(self::$PROGRAM_PREFIX . '%02d[%s]: ', $program_n + 1, $program_entry['name']);

            if ($program_entry['use'] == false) {
                continue;
            }
        }

        // 30001..: other
        $this->MaintainMedia('LogData', $this->Translate('Log data'), MEDIATYPE_DOCUMENT, '.dat', false, $vpos++, true);

        $objList = [];
        $chldIDs = IPS_GetChildrenIDs($this->InstanceID);
        foreach ($chldIDs as $chldID) {
            $obj = IPS_GetObject($chldID);
            if ($obj['ObjectType'] == OBJECTTYPE_VARIABLE) {
                if (preg_match('#^Sensor[^_]+_[0-9]+$#', $obj['ObjectIdent'], $r)) {
                    $objList[] = $obj;
                }
                if (preg_match('#^Station[^_]+_[0-9]+$#', $obj['ObjectIdent'], $r)) {
                    $objList[] = $obj;
                }
                if (preg_match('#^Program[^_]+_[0-9]+$#', $obj['ObjectIdent'], $r)) {
                    $objList[] = $obj;
                }
            }
        }
        foreach ($objList as $obj) {
            $ident = $obj['ObjectIdent'];
            if (!in_array($ident, $varList)) {
                $this->SendDebug(__FUNCTION__, 'unregister variable: ident=' . $ident, 0);
                $this->UnregisterVariable($ident);
            }
        }

        $module_disable = $this->ReadPropertyBoolean('module_disable');
        if ($module_disable) {
            $this->MaintainTimer('QueryStatus', 0);
            $this->MaintainTimer('SendVariables', 0);
            $this->MaintainStatus(IS_INACTIVE);
            return;
        }

        $mqtt_topic = $this->ReadPropertyString('mqtt_topic');
        $this->SetReceiveDataFilter('.*' . $mqtt_topic . '.*');

        $this->MaintainStatus(IS_ACTIVE);

        if ($this->Use4Ident('StationSelection')) {
            $this->SetStationSelection();
            $this->SetupStationSelection();
        }

        if ($this->Use4Ident('ProgramSelection')) {
            $this->SetProgramSelection();
            $this->SetupProgramSelection();
        }

        if ($this->Use4Ident('RainDelay')) {
            $this->SetupRainDelay();
        }

        if ($this->Use4Ident('PauseQueue')) {
            $this->SetupPauseQueue();
        }

        if (IPS_GetKernelRunlevel() == KR_READY) {
            $this->SetQueryInterval();
            $this->SetSendInterval();
        }
    }

    private function GetFormElements()
    {
        $formElements = $this->GetCommonFormElements('OpenSprinkler - Irrigation system');

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            return $formElements;
        }

        $formElements[] = [
            'type'    => 'CheckBox',
            'name'    => 'module_disable',
            'caption' => 'Disable instance',
        ];

        $formElements[] = [
            'type'  => 'ExpansionPanel',
            'items' => [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'host',
                    'caption' => 'Host',
                ],
                /*
                [
                    'type' => 'CheckBox',
                    'name' => 'use_https',
                    'caption' => 'Use HTTPS',
                ],
                 */
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'port',
                    'minimum' => 0,
                    'caption' => 'Port',
                ],
                [
                    'type'    => 'PasswordTextBox',
                    'name'    => 'password',
                    'caption' => 'Password',
                ],
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'mqtt_topic',
                    'caption' => 'MQTT topic',
                ],
                [
                    'type' => 'Label',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'query_interval',
                    'suffix'  => 'Seconds',
                    'minimum' => 0,
                    'caption' => 'Query interval',
                ],
            ],
            'caption' => 'Access configuration',
        ];

        $station_list = (array) @json_decode($this->ReadPropertyString('station_list'), true);
        $sensor_list = (array) @json_decode($this->ReadPropertyString('sensor_list'), true);
        $program_list = (array) @json_decode($this->ReadPropertyString('program_list'), true);

        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);
        $remote_extension = (bool) $this->GetArrayElem($controller_infos, 'remote_extension', false);

        $with_sensor = $remote_extension == false;
        $with_programs = $remote_extension == false;

        $formElements[] = [
            'type'  => 'ExpansionPanel',
            'items' => [
                [
                    'type'    => 'List',
                    'name'    => 'station_list',
                    'columns' => [
                        [
                            'caption' => 'No',
                            'name'    => 'sid',
                            'width'   => '50px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Name',
                            'name'    => 'name',
                            'width'   => 'auto',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Group',
                            'name'    => 'group',
                            'width'   => '100px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Interface',
                            'name'    => 'interface',
                            'width'   => '200px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Information',
                            'name'    => 'info',
                            'width'   => '500px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Use',
                            'name'    => 'use',
                            'width'   => '90px',
                            'edit'    => [
                                'type' => 'CheckBox'
                            ],
                        ],
                    ],
                    'values'   => $station_list,
                    'rowCount' => count($station_list) > 0 ? count($station_list) : 1,
                    'add'      => false,
                    'delete'   => false,
                    'caption'  => 'Stations',
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'sensor_errmsg',
                    'caption' => 'No sensors available because the controller is in remote expansion mode',
                    'visible' => $with_sensor == false,
                ],
                [
                    'type'    => 'List',
                    'name'    => 'sensor_list',
                    'columns' => [
                        [
                            'caption' => 'No',
                            'name'    => 'sni',
                            'width'   => '50px',
                            'save'    => true,
                        ],
                        [
                            'name'    => 'type',
                            'visible' => false,
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Name',
                            'name'    => 'name',
                            'width'   => 'auto',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Information',
                            'name'    => 'info',
                            'width'   => '500px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Use',
                            'name'    => 'use',
                            'width'   => '90px',
                            'edit'    => [
                                'type' => 'CheckBox'
                            ],
                        ],
                    ],
                    'values'   => $sensor_list,
                    'rowCount' => count($sensor_list) > 0 ? count($sensor_list) : 1,
                    'add'      => false,
                    'delete'   => false,
                    'caption'  => 'Sensors',
                    'visible'  => $with_sensor == true,
                ],
                [
                    'type'    => 'Label',
                    'name'    => 'program_errmsg',
                    'caption' => 'No programs available because the controller is in remote expansion mode',
                    'visible' => $with_programs == false,
                ],
                [
                    'type'    => 'List',
                    'name'    => 'program_list',
                    'columns' => [
                        [
                            'caption' => 'No',
                            'name'    => 'pid',
                            'width'   => '50px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Name',
                            'name'    => 'name',
                            'width'   => 'auto',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Information',
                            'name'    => 'info',
                            'width'   => '800px',
                            'save'    => true,
                        ],
                        [
                            'caption' => 'Use',
                            'name'    => 'use',
                            'width'   => '90px',
                            'edit'    => [
                                'type' => 'CheckBox'
                            ],
                        ],
                    ],
                    'values'   => $program_list,
                    'rowCount' => count($program_list) > 0 ? count($program_list) : 1,
                    'add'      => false,
                    'delete'   => false,
                    'caption'  => 'Programs',
                    'visible'  => $with_programs == true,
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Retrive configuration',
                    'onClick' => 'IPS_RequestAction($id, "RetriveConfiguration", "");',
                ],
            ],
            'caption' => 'Controller configuration',
        ];

        $feature = $this->GetArrayElem($controller_infos, 'feature', '');

        if ($feature != 'ASB') {
            $items = [
                [
                    'type'    => 'Label',
                    'caption' => 'Transferring variables to OpenSprinkler requires the software extension “ASB” from OpenSprinklerShop.de',
                ],
            ];
        } elseif ($remote_extension) {
            $items = [
                [
                    'type'    => 'Label',
                    'caption' => 'Transferring variables to OpenSprinkler is not available because the controller is in remote expansion mode',
                ],
            ];
        } else {
            $items = [
                [
                    'type'    => 'ValidationTextBox',
                    'name'    => 'variables_mqtt_topic',
                    'caption' => 'MQTT topic für sensor values',
                ],
                [
                    'type'    => 'NumberSpinner',
                    'name'    => 'send_interval',
                    'suffix'  => 'Seconds',
                    'minimum' => 0,
                    'caption' => 'Send variables interval',
                ],
                [
                    'type'    => 'List',
                    'name'    => 'variable_list',
                    'columns' => [
                        [
                            'name' => 'varID',
                            'add'  => 0,
                            'edit' => [
                                'type' => 'SelectVariable',
                            ],
                            'width'   => 'auto',
                            'caption' => 'Reference variable',
                        ],
                        [
                            'name' => 'mqtt_filter',
                            'add'  => '',
                            'edit' => [
                                'type' => 'ValidationTextBox',
                            ],
                            'width'   => '300px',
                            'caption' => 'Ident on the controller ("MQTT filter")',
                        ],
                        [
                            'add'   => true,
                            'name'  => 'use',
                            'width' => '90px',
                            'edit'  => [
                                'type' => 'CheckBox'
                            ],
                            'caption' => 'Use',
                        ],
                    ],
                    'add'     => true,
                    'delete'  => true,
                    'caption' => 'Variables to be transferred',
                ],
            ];
        }

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'caption' => 'External sensor values',
            'items'   => $items,
        ];

        $formElements[] = [
            'type'  => 'ExpansionPanel',
            'items' => [
                [
                    'type'    => 'Label',
                    'caption' => 'Variables for the whole system',
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_controller_daily_duration',
                            'caption' => 'Variables for daily watering time of the system',
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_controller_daily_usage',
                            'caption' => 'Variables for daily water usage of the system',
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_controller_total_duration',
                            'caption' => 'Variables for total watering time of the system',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => ' ... by activating this switch, additional variables are created and logged as counters',
                            'italic'  => true,
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_controller_total_usage',
                            'caption' => 'Variables for total water usage of the system',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => ' ... by activating this switch, additional variables are created and logged as counters',
                            'italic'  => true,
                        ],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Variables for each station',
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_station_daily_duration',
                            'caption' => 'Variables for daily watering time of a station',
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_station_daily_usage',
                            'caption' => 'Variables for daily water usage of a station',
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_station_total_duration',
                            'caption' => 'Variables for total watering time of a station',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => ' ... by activating this switch, additional variables are created and logged as counters',
                            'italic'  => true,
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_station_total_usage',
                            'caption' => 'Variables for total water usage of a station',
                        ],
                        [
                            'type'    => 'Label',
                            'caption' => ' ... by activating this switch, additional variables are created and logged as counters',
                            'italic'  => true,
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_station_last_run',
                            'caption' => 'Variables of the last run',
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_station_next_run',
                            'caption' => 'Variables of the next run',
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_station_usage',
                            'caption' => 'Variables with the water usage of the last run',
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_station_flow',
                            'caption' => 'Variables with the water flow of the last run',
                        ],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Summary',
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'CheckBox',
                            'name'    => 'with_summary',
                            'caption' => 'HTML box with summary'
                        ],
                    ],
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'SelectScript',
                            'name'    => 'summary_scriptID',
                            'caption' => 'Script for alternate summary'
                        ],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Log',
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'NumberSpinner',
                            'name'    => 'log_max_age',
                            'caption' => 'Maximum age of log until deletion',
                            'minimum' => 0,
                            'suffix'  => 'days'
                        ],
                    ],
                ],
                [
                    'type'    => 'Label',
                    'caption' => 'Notifications',
                ],
                [
                    'type'  => 'RowLayout',
                    'items' => [
                        [
                            'type' => 'Label',
                        ],
                        [
                            'type'    => 'SelectScript',
                            'name'    => 'notification_scriptID',
                            'width'   => '500px',
                            'caption' => 'Script to do notifications',
                        ],
                    ],
                ],
            ],
            'caption' => 'Other configurations'
        ];

        $formElements[] = [
            'type'    => 'ExpansionPanel',
            'items'   => [
                [
                    'type'    => 'Label',
                    'caption' => 'Used instead of the internal water flow sensor of the OpenSprinkler controller'
                ],
                [
                    'type'    => 'SelectVariable',
                    'name'    => 'WaterMeterID',
                    'caption' => 'Counter variable'
                ],
                [
                    'type'    => 'NumberSpinner',
                    'digits'  => 4,
                    'name'    => 'WaterMeterFactor',
                    'caption' => ' ... conversion factor to liter'
                ],
            ],
            'caption' => 'Optional external water meter'
        ];

        $this->MaintainReferences();

        return $formElements;
    }

    private function GetFormActions()
    {
        $formActions = [];

        if ($this->GetStatus() == self::$IS_UPDATEUNCOMPLETED) {
            $formActions[] = $this->GetCompleteUpdateFormAction();

            $formActions[] = $this->GetInformationFormAction();
            $formActions[] = $this->GetReferencesFormAction();

            return $formActions;
        }

        $formActions[] = [
            'type'  => 'RowLayout',
            'items' => [
                [
                    'type'    => 'Button',
                    'caption' => 'Query status',
                    'onClick' => 'IPS_RequestAction($id, "QueryStatus", "");',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Send variables',
                    'onClick' => 'IPS_RequestAction($id, "SendVariables", "");',
                ],
                [
                    'type'    => 'Button',
                    'caption' => 'Get controller logdata',
                    'onClick' => 'IPS_RequestAction($id, "GetControllerLog", "");',
                ],
            ],
        ];

        $formActions[] = [
            'type'     => 'ExpansionPanel',
            'caption'  => 'Expert area',
            'expanded' => false,
            'items'    => [
                $this->GetInstallVarProfilesFormItem(),
                [
                    'type'    => 'Button',
                    'caption' => 'Adjust variable names',
                    'confirm' => 'This adjusts the first part von the variable name acording to the retrived configuration',
                    'onClick' => 'IPS_RequestAction($id, "AdjustVariablenames", "");',
                ],
            ],
        ];

        $formActions[] = [
            'type'     => 'ExpansionPanel',
            'caption'  => 'Test area',
            'expanded' => false,
            'items'    => [
                [
                    'type' => 'TestCenter',
                ],
            ]
        ];

        $formActions[] = $this->GetInformationFormAction();
        $formActions[] = $this->GetReferencesFormAction();

        return $formActions;
    }

    private function SetQueryInterval(int $sec = null)
    {
        if (is_null($sec)) {
            $sec = $this->ReadPropertyInteger('query_interval');
        }
        $this->MaintainTimer('QueryStatus', $sec * 1000);
    }

    private function SetSendInterval(int $sec = null)
    {
        if (is_null($sec)) {
            $sec = $this->ReadPropertyInteger('send_interval');
        }
        $this->MaintainTimer('SendVariables', $sec * 1000);
    }

    private function WateringLevelChangeable()
    {
        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);
        $weather_method = $this->GetArrayElem($controller_infos, 'weather_method', self::$WEATHER_METHOD_MANUAL);
        return in_array($weather_method, [self::$WEATHER_METHOD_MANUAL]);
    }

    private function AdjustTimestamp($tstamp)
    {
        if ($tstamp > 0) {
            $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);
            $timezone_offset = $this->GetArrayElem($controller_infos, 'timezone_offset', 0);
            $tstamp -= $timezone_offset;
        }
        return $tstamp;
    }

    private function ConvertPulses2Volume($count)
    {
        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);
        $pulse_volume = $this->GetArrayElem($controller_infos, 'pulse_volume', 0);
        return $count * $pulse_volume;
    }

    private function GetCurRun($sid)
    {
        $cur_runs = (array) @json_decode($this->ReadAttributeString('cur_runs'), true);
        // $this->SendDebug(__FUNCTION__, 'cur_runs=' . print_r($cur_runs, true), 0);

        foreach ($cur_runs as $cur_run) {
            if ($cur_run['sid'] == $sid) {
                $this->SendDebug(__FUNCTION__, 'found cur_run=' . print_r($cur_run, true), 0);
                return $cur_run;
            }
        }
        return false;
    }

    private function SetCurRun($run)
    {
        $cur_runs = (array) @json_decode($this->ReadAttributeString('cur_runs'), true);
        // $this->SendDebug(__FUNCTION__, 'cur_runs=' . print_r($cur_runs, true), 0);

        $b = false;
        for ($i = 0; $i < count($cur_runs); $i++) {
            if ($cur_runs[$i]['sid'] == $run['sid']) {
                foreach (['start', 'water_counter'] as $key) {
                    if (isset($cur_runs[$i][$key]) == false || $cur_runs[$i][$key] == 0) {
                        if (isset($run[$key])) {
                            $cur_runs[$i][$key] = $run[$key];
                        }
                    }
                }
                foreach (['left', 'flow'] as $key) {
                    if (isset($run[$key])) {
                        $cur_runs[$i][$key] = $run[$key];
                    }
                }
                $this->SendDebug(__FUNCTION__, 'update cur_run=' . print_r($cur_runs[$i], true), 0);
                $b = true;
                break;
            }
        }

        if ($b == false) {
            $cur_run = [];
            foreach (['sid', 'start', 'water_counter', 'left', 'flow'] as $key) {
                if (isset($run[$key])) {
                    $cur_run[$key] = $run[$key];
                }
            }
            if (isset($run['start']) == false) {
                $cur_run['start'] = true;
            }
            $cur_runs[] = $cur_run;
            $this->SendDebug(__FUNCTION__, 'create cur_run=' . print_r($cur_run, true), 0);
        }

        if ($cur_runs != (array) @json_decode($this->ReadAttributeString('cur_runs'), true)) {
            $this->SendDebug(__FUNCTION__, 'write cur_runs=' . print_r($cur_runs, true), 0);
            $this->WriteAttributeString('cur_runs', json_encode($cur_runs));
        }
    }

    private function DelCurRun($sid)
    {
        $cur_runs = (array) @json_decode($this->ReadAttributeString('cur_runs'), true);
        // $this->SendDebug(__FUNCTION__, 'cur_runs=' . print_r($cur_runs, true), 0);

        $_runs = [];
        foreach ($cur_runs as $cur_run) {
            if ($cur_run['sid'] != $sid) {
                $_runs[] = $cur_run;
            } else {
                $this->SendDebug(__FUNCTION__, 'delete cur_run=' . print_r($cur_run, true), 0);
            }
        }

        if ($_runs != (array) @json_decode($this->ReadAttributeString('cur_runs'), true)) {
            $this->SendDebug(__FUNCTION__, 'update cur_runs=' . print_r($_runs, true), 0);
            $this->WriteAttributeString('cur_runs', json_encode($_runs));
        }
    }

    private function DecodeDaterange($date)
    {
        $month = (($date & ($date & ~0b11111)) >> 5);
        $day = ($date & 0b11111);
        return ['month' => $month, 'day' => $day];
    }

    private function EncodeDaterange($month, $day)
    {
        return ($month << 5) + $day;
    }

    private function CheckDailyValues()
    {
        $ts_today = strtotime(date('d.m.Y', time()));
        $ts_watch = $this->ReadAttributeInteger('daily_reference');
        if ($ts_today == $ts_watch) {
            return;
        }

        $this->SendDebug(__FUNCTION__, 'reset daily value (old=' . date('d.m.Y', $ts_watch) . ', new=' . date('d.m.Y', $ts_today) . ')', 0);

        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);

        if ($this->Use4Ident('DailyWaterUsage')) {
            $this->SetValue('DailyWaterUsage', 0);
        }
        if ($this->Use4Ident('DailyDuration')) {
            $this->SetValue('DailyDuration', 0);
        }

        $station_list = (array) @json_decode($this->ReadPropertyString('station_list'), true);
        $station_infos = (array) @json_decode($this->ReadAttributeString('station_infos'), true);

        for ($station_n = 0; $station_n < count($station_list); $station_n++) {
            $station_entry = $station_list[$station_n];

            $station_info = [];
            for ($i = 0; $i < count($station_infos); $i++) {
                if ($station_infos[$i]['sid'] == $station_n) {
                    $station_info = $station_infos[$i];
                    break;
                }
            }

            if ($station_entry['use'] == false) {
                continue;
            }

            $post = '_' . ($station_n + 1);

            if ($this->Use4Ident('StationDailyWaterUsage', $station_n)) {
                $this->SetValue('StationDailyWaterUsage' . $post, 0);
            }
            if ($this->Use4Ident('StationDailyDuration', $station_n)) {
                $this->SetValue('StationDailyDuration' . $post, 0);
            }
        }

        $this->WriteAttributeInteger('daily_reference', $ts_today);
    }

    private function QueryStatus()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return;
        }

        $data = $this->do_HttpRequest('ja', []);
        if ($data == false) {
            $this->SendDebug(__FUNCTION__, 'no data', 0);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return;
        }
        $jdata = @json_decode($data, true);
        if ($jdata == false) {
            $this->SendDebug(__FUNCTION__, 'malformed data', 0);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);

        $this->CheckDailyValues();

        $this->SaveInfos($jdata);

        $station_list = (array) @json_decode($this->ReadPropertyString('station_list'), true);
        $sensor_list = (array) @json_decode($this->ReadPropertyString('sensor_list'), true);
        $program_list = (array) @json_decode($this->ReadPropertyString('program_list'), true);

        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);
        $station_infos = (array) @json_decode($this->ReadAttributeString('station_infos'), true);
        $program_infos = (array) @json_decode($this->ReadAttributeString('program_infos'), true);

        $feature = $this->GetArrayElem($controller_infos, 'feature', '');
        $remote_extension = (bool) $this->GetArrayElem($controller_infos, 'remote_extension', false);
        $has_flowmeter = (bool) $this->GetArrayElem($controller_infos, 'has_flowmeter', false);
        $station_count = $this->GetArrayElem($controller_infos, 'station_count', 0);

        $now = time();

        $fnd = true;

        if ($this->Use4Ident('ControllerEnabled')) {
            $en = $this->GetArrayElem($jdata, 'settings.en', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... ControllerEnabled (settings.en)=' . $en, 0);
                $this->SetValue('ControllerEnabled', $en);
            }
        }

        if ($this->Use4Ident('WateringLevel')) {
            $wl = $this->GetArrayElem($jdata, 'options.wl', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... WateringLevel (options.wl)=' . $wl, 0);
                $this->SetValue('WateringLevel', $wl);
            }
        }

        if ($this->Use4Ident('RainDelay')) {
            $rdst = $this->GetArrayElem($jdata, 'settings.rdst', 0, $fnd);
            if ($fnd) {
                $rdst_gm = $this->AdjustTimestamp($rdst);
                $this->SendDebug(__FUNCTION__, '... RainDelayUntil (settings.rdst)=' . $rdst . ' => ' . ($rdst_gm ? date('d.m.y H:i:s', $rdst_gm) : '-'), 0);
                $this->SetValue('RainDelayUntil', $rdst_gm);
            }
        }

        if ($this->Use4Ident('PauseQueue')) {
            $pt = $this->GetArrayElem($jdata, 'settings.pt', 0, $fnd);
            if ($fnd) {
                $ts = $pt ? time() + $pt : 0;
                $this->SendDebug(__FUNCTION__, '... PauseQueueUntil (settings.pt)=' . $pt . ' => ' . ($ts ? date('d.m.y H:i:s', $ts) : '-'), 0);
                $this->SetValue('PauseQueueUntil', $ts);
            }
        }

        if ($this->Use4Ident('DeviceTime')) {
            $devt = $this->GetArrayElem($jdata, 'settings.devt', 0, $fnd);
            if ($fnd) {
                $devt_gm = $this->AdjustTimestamp($devt);
                $this->SendDebug(__FUNCTION__, '... DeviceTime (settings.devt)=' . $devt . ' => ' . date('d.m.y H:i:s', $devt_gm), 0);
                $this->SetValue('DeviceTime', $devt_gm);
            }
        }

        if ($this->Use4Ident('WifiStrength')) {
            $rssi = $this->GetArrayElem($jdata, 'settings.RSSI', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... WifiStrength (settings.RSSI)=' . $rssi, 0);
                $this->SetValue('WifiStrength', $rssi);
            }
        }

        if ($this->Use4Ident('WeatherQueryTstamp')) {
            $lswc = $this->GetArrayElem($jdata, 'settings.lswc', 0, $fnd);
            if ($fnd) {
                $lswc_gm = $this->AdjustTimestamp($lswc);
                $this->SendDebug(__FUNCTION__, '... WeatherQueryTstamp (settings.lswc)=' . $lswc . ' => ' . ($lswc_gm ? date('d.m.y H:i:s', $lswc_gm) : '-'), 0);
                $this->SetValue('WeatherQueryTstamp', $lswc_gm);
            }
        }

        if ($this->Use4Ident('WeatherQueryStatus')) {
            $wterr = $this->GetArrayElem($jdata, 'settings.wterr', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... WeatherQueryStatus (settings.wterr)=' . $wterr, 0);
                $this->SetValue('WeatherQueryStatus', $wterr);
            }
        }

        if ($this->Use4Ident('LastRebootTstamp')) {
            $lupt = $this->GetArrayElem($jdata, 'settings.lupt', 0, $fnd);
            if ($fnd) {
                $lupt_gm = $this->AdjustTimestamp($lupt);
                $this->SendDebug(__FUNCTION__, '... LastRebootTstamp (settings.lupt)=' . $lupt . ' => ' . date('d.m.y H:i:s', $lupt_gm), 0);
                $this->SetValue('LastRebootTstamp', $lupt_gm);
            }
        }

        if ($this->Use4Ident('LastRebootCause')) {
            $lrbtc = $this->GetArrayElem($jdata, 'settings.lrbtc', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... LastRebootCause (settings.lrbtc)=' . $lrbtc, 0);
                $this->SetValue('LastRebootCause', $lrbtc);
            }
        }

        if ($this->Use4Ident('CurrentDraw')) {
            $curr = $this->GetArrayElem($jdata, 'settings.curr', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... CurrentDraw (settings.curr)=' . $curr, 0);
                $this->SetValue('CurrentDraw', $curr);
            }
        }

        if ($this->Use4Ident('WaterFlowrate')) {
            $flwrt = $this->GetArrayElem($jdata, 'settings.flwrt', 30);
            $flcrt = $this->GetArrayElem($jdata, 'settings.flcrt', 0, $fnd);
            if ($fnd) {
                $flow_rate = $this->ConvertPulses2Volume($flcrt) / ($flwrt / 60.0);
                $this->SendDebug(__FUNCTION__, '... WaterFlowrate (settings.flwrt)=' . $flwrt . '/(settings.flcrt)=' . $flcrt . ' => ' . $flow_rate, 0);
                $this->SetValue('WaterFlowrate', $flow_rate);
            }
        }

        if ($this->Use4Ident('SensorState_1')) {
            $sn1 = $this->GetArrayElem($jdata, 'settings.sn1', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... SensorState_1 (settings.sn1)=' . $sn1, 0);
                $this->SetValue('SensorState_1', $sn1);
            }
        }

        if ($this->Use4Ident('SensorState_2')) {
            $sn2 = $this->GetArrayElem($jdata, 'settings.sn2', 0, $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... SensorState_2 (settings.sn2)=' . $sn2, 0);
                $this->SetValue('SensorState_2', $sn2);
            }
        }

        $stn_dis = (array) $this->GetArrayElem($jdata, 'stations.stn_dis', [], $fnd);
        if ($fnd) {
            $sidV = [];
            for ($sid = 0; $sid < count($stn_dis) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $stn_dis)) {
                    $sidV[] = $sid;
                }
            }
            $this->SendDebug(__FUNCTION__, '... (stations.stn_dis)=' . print_r($stn_dis, true) . ' => sid=' . implode(', ', $sidV), 0);
        }

        $ignore_rain = $this->GetArrayElem($jdata, 'stations.ignore_rain', [], $fnd);
        if ($fnd) {
            $sidV = [];
            for ($sid = 0; $sid < count($ignore_rain) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $ignore_rain)) {
                    $sidV[] = $sid;
                }
            }
            $this->SendDebug(__FUNCTION__, '... (stations.ignore_rain)=' . print_r($ignore_rain, true) . ' => sid=' . implode(', ', $sidV), 0);
        }

        $ignore_sn1 = $this->GetArrayElem($jdata, 'stations.ignore_sn1', [], $fnd);
        if ($fnd) {
            $sidV = [];
            for ($sid = 0; $sid < count($ignore_sn1) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $ignore_sn1)) {
                    $sidV[] = $sid;
                }
            }
            $this->SendDebug(__FUNCTION__, '... (stations.ignore_sn1)=' . print_r($ignore_sn1, true) . ' => sid=' . implode(', ', $sidV), 0);
        }

        $ignore_sn2 = $this->GetArrayElem($jdata, 'stations.ignore_sn2', [], $fnd);
        if ($fnd) {
            $sidV = [];
            for ($sid = 0; $sid < count($ignore_sn2) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $ignore_sn2)) {
                    $sidV[] = $sid;
                }
            }
            $this->SendDebug(__FUNCTION__, '... (stations.ignore_sn2)=' . print_r($ignore_sn2, true) . ' => sid=' . implode(', ', $sidV), 0);
        }

        $sbits = (array) $this->GetArrayElem($jdata, 'settings.sbits', [], $fnd);
        if ($fnd) {
            $sidV = [];
            for ($sid = 0; $sid < count($sbits) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $sbits)) {
                    $sidV[] = $sid;
                }
            }
            $this->SendDebug(__FUNCTION__, '... (settings.sbits)=' . print_r($sbits, true) . ' => sid=' . implode(', ', $sidV), 0);
        }

        if ($has_flowmeter && $feature == 'ASB') {
            $stn_favg = (array) $this->GetArrayElem($jdata, 'stations.stn_favg', [], $fnd);
            if ($fnd) {
                $this->SendDebug(__FUNCTION__, '... (stations.stn_favg)=' . print_r($stn_favg, true), 0);
            }
        }

        for ($sid = 0; $sid < $station_count; $sid++) {
            for ($n = 0; $n < count($station_list); $n++) {
                if ($station_list[$n]['sid'] == $sid) {
                    break;
                }
            }
            if ($n == count($station_list)) {
                continue;
            }
            $station_entry = $station_list[$n];

            if ($station_entry['use'] == false) {
                continue;
            }

            $station_info = false;
            for ($i = 0; $i < count($station_infos); $i++) {
                if ($station_infos[$i]['sid'] == $sid) {
                    $station_info = $station_infos[$i];
                    break;
                }
            }
            if ($station_info == false) {
                continue;
            }

            $post = '_' . ($sid + 1);

            $nextStart = 0;
            $nextDur = 0;
            $curLeft = 0;

            $is_master = $this->GetArrayElem($station_info, 'is_master', 0);
            if ($is_master == false && $remote_extension == false) {
                $ps = (array) $this->GetArrayElem($jdata, 'settings.ps', [], $fnd);
                if ($fnd) {
                    $ps_rem = $ps[$sid][1];
                    $ps_start = $this->AdjustTimestamp($ps[$sid][2]);
                } else {
                    $ps_rem = 0;
                    $ps_start = 0;
                }

                if ($this->idx_in_bytes($sid, $stn_dis)) {
                    $state = self::$STATION_STATE_DISABLED;
                } elseif ($ps[$sid][0] != 0) {
                    if ($this->idx_in_bytes($sid, $sbits)) {
                        $state = self::$STATION_STATE_WATERING;
                        $curLeft = $ps_rem;
                    } else {
                        $state = self::$STATION_STATE_QUEUED;
                        $nextStart = $ps_start;
                        $nextDur = $ps_rem;
                    }
                } else {
                    $state = self::$STATION_STATE_READY;
                }
            } else {
                if ($this->idx_in_bytes($sid, $stn_dis)) {
                    $state = self::$STATION_STATE_DISABLED;
                } elseif ($this->idx_in_bytes($sid, $sbits)) {
                    $state = self::$STATION_STATE_OPENED;
                } else {
                    $state = self::$STATION_STATE_CLOSED;
                }
            }

            if ($this->Use4Ident('StationState', $sid)) {
                $this->SendDebug(__FUNCTION__, '... StationState' . $post . ' => ' . $state, 0);
                $this->SetValue('StationState' . $post, $state);
            }

            if ($this->Use4Ident('StationTimeLeft', $sid)) {
                $this->SendDebug(__FUNCTION__, '... StationTimeLeft' . $post . ' => ' . $curLeft, 0);
                $this->SetValue('StationTimeLeft' . $post, $curLeft);
            }

            if ($this->Use4Ident('StationNextRun', $sid)) {
                $this->SendDebug(__FUNCTION__, '... StationNextRun' . $post . ' => ' . $nextStart, 0);
                $this->SetValue('StationNextRun' . $post, $nextStart);
            }

            if ($this->Use4Ident('StationNextDuration', $sid)) {
                $this->SendDebug(__FUNCTION__, '... StationNextDuration' . $post . ' => ' . $nextDur, 0);
                $this->SetValue('StationNextDuration' . $post, $nextDur);
            }

            $lrun = (array) $this->GetArrayElem($jdata, 'settings.lrun', [], $fnd);
            if ($fnd) {
                $lr_sid = $lrun[0];
                $lr_dur = $lrun[2];
                $lr_end = $this->AdjustTimestamp($lrun[3]);
            } else {
                $lr_sid = 0;
                $lr_dur = 0;
                $lr_end = 0;
            }
            if ($lr_sid == $sid && $lr_dur != 0 && $lr_end != 0) {
                $lr_start = $lr_end - $lr_dur;
                if ($this->Use4Ident('StationLastRun', $sid)) {
                    $this->SendDebug(__FUNCTION__, '... StationLastRun' . $post . ' => ' . date('d.m.y H:i:s', $lr_start), 0);
                    $this->SetValue('StationLastRun' . $post, $lr_start);
                }
                if ($this->Use4Ident('StationLastDuration', $sid)) {
                    $this->SendDebug(__FUNCTION__, '... StationLastDuration' . $post . ' => ' . $lr_dur, 0);
                    $this->SetValue('StationLastDuration' . $post, $lr_dur);
                }
            }

            if ($this->Use4Ident('StationFlowAverage', $sid)) {
                $favg = $stn_favg[$sid] / 100;
                $this->SendDebug(__FUNCTION__, '... StationFlowAverage' . $post . ' => ' . $favg, 0);
                $this->SetValue('StationFlowAverage' . $post, $favg);
            }

            $cur_run = $this->GetCurRun($sid);
            $_post = '_' . ($sid + 1);
            if ($state == self::$STATION_STATE_WATERING) {
                if ($cur_run === false) {
                    $cur_run = [
                        'sid'   => $sid,
                        'start' => time(),
                    ];
                    if ($this->HasWaterMeter()) {
                        $cur_run['water_counter'] = $this->GetWaterMeter();
                    }
                }
                $cur_run['left'] = $curLeft;
                if ($this->Use4Ident('WaterFlowrate')) {
                    $cur_run['flow'] = $this->GetValue('WaterFlowrate');
                }
                $this->SetCurRun($cur_run);
            } else {
                if ($cur_run !== false) {
                    $duration = $lr_dur;

                    if ($this->HasWaterMeter()) {
                        $water_counter = $this->GetWaterMeter();
                        $last_water_counter = $cur_run['water_counter'];
                        $usage = round((float) $water_counter - (float) $last_water_counter, self::$PRECISION_USAGE);
                        if ($duration > 0) {
                            $flow = round($usage / (float) ($duration / 60), self::$PRECISION_FLOW);
                        } else {
                            $flow = 0;
                        }
                        $this->SendDebug(__FUNCTION__, 'water_counter=' . $last_water_counter . ' ... ' . $water_counter . ' => usage=' . $usage . ' l, real flow=' . $flow . ' l/min', 0);
                    } else {
                        $flow = isset($cur_run['flow']) ? $cur_run['flow'] : 0;
                        if ($duration > 0) {
                            $usage = round($flow * (float) ($duration / 60), self::$PRECISION_USAGE);
                        } else {
                            $usage = 0;
                        }
                        $this->SendDebug(__FUNCTION__, 'usage=' . $usage . ' l, flow=' . $flow . ' l/min', 0);
                    }

                    if ($this->Use4Ident('TotalWaterUsage')) {
                        $old_total_usage = $this->GetValue('TotalWaterUsage');
                        $total_usage = round($old_total_usage + $usage, self::$PRECISION_USAGE);
                        $this->SendDebug(__FUNCTION__, '... TotalWaterUsage => ' . $total_usage . ' (old=' . $old_total_usage . ')', 0);
                        $this->SetValue('TotalWaterUsage', $total_usage);
                    }

                    if ($this->Use4Ident('DailyWaterUsage')) {
                        $old_daily_usage = $this->GetValue('DailyWaterUsage');
                        $daily_usage = round($old_daily_usage + $usage, self::$PRECISION_USAGE);
                        $this->SendDebug(__FUNCTION__, '... DailyWaterUsage => ' . $daily_usage . ' (old=' . $old_daily_usage . ')', 0);
                        $this->SetValue('DailyWaterUsage', $daily_usage);
                    }

                    if ($this->Use4Ident('StationWaterUsage', $sid)) {
                        $this->SendDebug(__FUNCTION__, '... StationWaterUsage' . $_post . ' => ' . $usage, 0);
                        $this->SetValue('StationWaterUsage' . $_post, $usage);
                    }

                    if ($this->Use4Ident('StationTotalWaterUsage', $sid)) {
                        $old_total_usage = $this->GetValue('StationTotalWaterUsage' . $_post);
                        $total_usage = round($old_total_usage + $usage, self::$PRECISION_USAGE);
                        $this->SendDebug(__FUNCTION__, '... StationTotalWaterUsage' . $_post . ' => ' . $total_usage . ' (old=' . $old_total_usage . ')', 0);
                        $this->SetValue('StationTotalWaterUsage' . $_post, $total_usage);
                    }

                    if ($this->Use4Ident('StationDailyWaterUsage', $sid)) {
                        $old_daily_usage = $this->GetValue('StationDailyWaterUsage' . $_post);
                        $daily_usage = round($old_daily_usage + $usage, self::$PRECISION_USAGE);
                        $this->SendDebug(__FUNCTION__, '... StationDailyWaterUsage' . $_post . ' => ' . $daily_usage . ' (old=' . $old_daily_usage . ')', 0);
                        $this->SetValue('StationDailyWaterUsage' . $_post, $daily_usage);
                    }

                    $start = $cur_run['start'];
                    $end = $start + $duration;
                    $this->AddLog4Station($sid, $start, $end, $duration, $flow, $usage);

                    $this->DelCurRun($sid);
                }
            }
        }

        $this->SetValue('LastUpdate', $now);

        if ($this->Use4Ident('StationSelection')) {
            $this->SetStationSelection();
            $this->SetupStationSelection();
        }

        if ($this->Use4Ident('ProgramSelection')) {
            $this->SetProgramSelection();
            $this->SetupProgramSelection();
        }

        if ($this->Use4Ident('RainDelay')) {
            $this->SetupRainDelay();
        }

        if ($this->Use4Ident('PauseQueue')) {
            $this->SetupPauseQueue();
        }

        if ($this->Use4Ident('WateringLevel')) {
            $e = $this->Enable4Ident('WateringLevel');
            $this->MaintainAction('WateringLevel', $e);
        }

        $db_data = $this->do_HttpRequest('db', []);
        if ($db_data == false) {
            $this->SendDebug(__FUNCTION__, 'no db_data', 0);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return;
        }
        $db_jdata = @json_decode($db_data, true);
        if ($db_jdata == false) {
            $this->SendDebug(__FUNCTION__, 'malformed db_data', 0);
            IPS_SemaphoreLeave($this->SemaphoreID);
            return;
        }
        $this->SendDebug(__FUNCTION__, 'db_jdata=' . print_r($db_jdata, true), 0);

        if ($this->Use4Ident('Summary')) {
            $days = (int) $this->GetValue('SummaryDays');
            $groupBy = (int) $this->GetValue('SummaryGroupBy');
            $until = time();
            $from = strtotime(date('d.m.Y 00:00:00', $until - (24 * 60 * 60 * $days)));

            $html = $this->BuildSummary($from, $until, $groupBy, []);
            $this->SetValue('Summary', $html);
        }

        $this->SetQueryInterval();

        IPS_SemaphoreLeave($this->SemaphoreID);
    }

    private function SaveInfos($jdata)
    {
        $station_list = (array) @json_decode($this->ReadPropertyString('station_list'), true);
        $sensor_list = (array) @json_decode($this->ReadPropertyString('sensor_list'), true);
        $program_list = (array) @json_decode($this->ReadPropertyString('program_list'), true);

        $fnd = true;

        $firmware = '';
        $fwv = (string) $this->GetArrayElem($jdata, 'options.fwv', '', $fnd);
        if ($fnd) {
            for ($i = 0; $i < strlen($fwv); $i++) {
                if ($firmware != '') {
                    $firmware .= '.';
                }
                $firmware .= substr($fwv, $i, 1);
            }
        }
        $fwm = (string) $this->GetArrayElem($jdata, 'options.fwm', '', $fnd);
        if ($fnd) {
            $firmware .= '(' . $fwm . ')';
        }

        $hardware = '';
        $hwv = (string) $this->GetArrayElem($jdata, 'options.hwv', '', $fnd);
        if ($fnd) {
            for ($i = 0; $i < strlen($hwv); $i++) {
                if ($hardware != '') {
                    $hardware .= '.';
                }
                $hardware .= substr($hwv, $i, 1);
            }
        }
        $hwt = $this->GetArrayElem($jdata, 'options.hwt', 0, $fnd);
        if ($fnd) {
            $hwt2str = [
                0xAC => 'AC',
                0xDC => 'DC',
                0x1A => 'Latch',
            ];
            if (isset($hwt2str[$hwt])) {
                $hardware .= ' ' . $hwt2str[$hwt];
            }
        }

        $feature = $this->GetArrayElem($jdata, 'options.feature', '');

        $remote_extension = (bool) $this->GetArrayElem($jdata, 'options.re', false);

        $this->SendDebug(__FUNCTION__, 'firmware=' . $firmware . ', hardware=' . $hardware . ', feature=' . $feature . ', remote_extension=' . $this->bool2str($remote_extension), 0);

        $nbrd = $this->GetArrayElem($jdata, 'settings.nbrd', 1);
        $station_count = $nbrd * 8;

        $timezone_offset = 0;
        $tz = $this->GetArrayElem($jdata, 'options.tz', 0, $fnd);
        if ($fnd) {
            $timezone_offset = ($tz - 48) / 4 * 3600;
        }

        $pulse_volume = 0;
        $fpr0 = $this->GetArrayElem($jdata, 'options.fpr0', 0, $fnd);
        if ($fnd) {
            $fpr1 = $this->GetArrayElem($jdata, 'options.fpr1', 0, $fnd);
            if ($fnd) {
                $pulse_volume = (($fpr1 << 8) + $fpr0) / 100.0;
            }
        }

        $weather_method = self::$WEATHER_METHOD_MANUAL;
        $uwt = $this->GetArrayElem($jdata, 'options.uwt', 0, $fnd);
        if ($fnd) {
            $weather_method = $this->bit_clear($uwt, 7);
        }

        $ps = (array) $this->GetArrayElem($jdata, 'settings.ps', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (settings.ps)=' . print_r($ps, true), 0);
            for ($ps_sid = 0; $ps_sid < count($ps); $ps_sid++) {
                $ps_pid = $ps[$ps_sid][0];
                $rem = $ps[$ps_sid][1];
                $start = $this->AdjustTimestamp($ps[$ps_sid][2]);
                $gid = $ps[$ps_sid][3];
                if ($ps_pid != 0 || $rem != 0 || $start != 0) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $ps_sid . ', pid=' . $ps_pid . ', rem=' . $rem . 's, start=' . ($start ? date('d.m.y H:i:s', $start) : '-') . ', gid=' . $this->Group2String($gid), 0);
                }
            }
        }

        $running_programsV = [];
        $nprogs = $this->GetArrayElem($jdata, 'programs.nprogs', 0);
        for ($pid = 0; $pid < $nprogs; $pid++) {
            for ($n = 0; $n < count($program_list); $n++) {
                if ($program_list[$n]['pid'] == $pid) {
                    break;
                }
            }
            if ($n == count($program_list)) {
                continue;
            }
            $program_entry = $program_list[$n];

            if ($program_list[$n] == false) {
                continue;
            }

            $post = '_' . ($pid + 1);

            $pname = '';
            for ($ps_sid = 0; $ps_sid < count($ps); $ps_sid++) {
                $ps_pid = $ps[$ps_sid][0];
                if ($ps_pid == 0) {
                    continue;
                }
                if ($ps_pid == ($pid + 1)) {
                    $pname = $program_entry['name'];
                    break;
                } elseif ($ps_pid == self::$ADHOC_PROGRAM) {
                    $pname = $this->Translate('Adhoc program');
                    break;
                } elseif ($ps_pid == self::$MANUAL_STATION_START) {
                    $pname = $this->Translate('Manual station start');
                    break;
                }
            }
            if ($pname != '' && in_array($pname, $running_programsV) == false) {
                $running_programsV[] = $pname;
            }
        }

        $running_stationsV = [];
        $sbits = (array) $this->GetArrayElem($jdata, 'settings.sbits', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (settings.sbits)=' . print_r($sbits, true), 0);
            for ($sid = 0; $sid < count($sbits) * 8; $sid++) {
                if ($this->idx_in_bytes($sid, $sbits)) {
                    $this->SendDebug(__FUNCTION__, '....... sid=' . $sid . ', active', 0);
                    for ($n = 0; $n < count($station_list); $n++) {
                        $station_entry = $station_list[$n];
                        if ($station_entry['sid'] == $sid) {
                            if ($station_entry['use']) {
                                $running_stationsV[] = $station_entry['name'];
                            }
                            break;
                        }
                    }
                    if ($n == count($station_list)) {
                        $running_stationsV[] = $this->Translate('Unknown station') . ' ' . $sid;
                    }
                }
            }
        }

        $last_program = '';
        $last_station = '';
        $lrun = (array) $this->GetArrayElem($jdata, 'settings.lrun', [], $fnd);
        if ($fnd) {
            $this->SendDebug(__FUNCTION__, '... (settings.lrun)=' . print_r($lrun, true), 0);
            $lr_sid = $lrun[0];
            $lr_pid = $lrun[1];
            $lr_dur = $lrun[2];
            $lr_end = $this->AdjustTimestamp($lrun[3]);
            $this->SendDebug(__FUNCTION__, '....... sid=' . $lr_sid . ', pid=' . $lr_pid . ', dur=' . $lr_dur . ', end=' . ($lr_end ? date('d.m.y H:i:s', $lr_end) : '-'), 0);
            if ($lr_pid != 0) {
                if ($lr_pid == self::$ADHOC_PROGRAM) {
                    $last_program = $this->Translate('Adhoc program');
                } elseif ($lr_pid == self::$MANUAL_STATION_START) {
                    $last_program = $this->Translate('Manual station start');
                } else {
                    for ($n = 0; $n < count($program_list); $n++) {
                        $program_entry = $program_list[$n];
                        if ($program_entry['pid'] == ($lr_pid - 1)) {
                            if ($program_entry['use']) {
                                $last_program = $program_entry['name'];
                            }
                            break;
                        }
                    }
                    if ($n == count($program_list)) {
                        $running_programsV[] = $this->Translate('Unknown program') . ' ' . $lr_pid;
                    }
                }
            }

            if ($lr_sid != 0) {
                for ($n = 0; $n < count($station_list); $n++) {
                    $station_entry = $station_list[$n];
                    if ($station_entry['sid'] == $lr_sid) {
                        if ($station_entry['use']) {
                            $last_station = $station_entry['name'];
                            if ($lr_dur != 0 && $lr_end != 0) {
                                $lr_start = $lr_end - $lr_dur;
                                $last_station .= '[' . $this->seconds2duration($lr_dur) . ' @ ' . date('d.m H:i', $lr_start) . ']';
                            }
                        }
                        break;
                    }
                }
                if ($n == count($station_list)) {
                    $running_stationsV[] = $this->Translate('Unknown station') . ' ' . $lr_sid;
                }
            }
        }

        $has_flowmeter = false;
        $sensor_type = [
            1 => self::$SENSOR_TYPE_NONE,
            2 => self::$SENSOR_TYPE_NONE,
        ];

        for ($sensor_n = 0; $sensor_n < count($sensor_list); $sensor_n++) {
            $sensor_entry = $sensor_list[$sensor_n];
            if ($sensor_entry['sni'] > self::$MAX_INT_SENSORS) {
                continue;
            }

            if ($sensor_entry['use'] == false) {
                continue;
            }

            $snt = $this->GetArrayElem($sensor_entry, 'type', self::$SENSOR_TYPE_NONE);
            if ($snt == self::$SENSOR_TYPE_FLOW) {
                $has_flowmeter = true;
            }

            $sensor_type[$sensor_entry['sni']] = $snt;
        }

        $controller_infos = [
            'firmware'         => $firmware,
            'hardware'         => $hardware,
            'feature'          => $feature,
            'remote_extension' => $remote_extension,
            'station_count'    => $station_count,
            'timezone_offset'  => $timezone_offset,
            'weather_method'   => $weather_method,
            'has_flowmeter'    => $has_flowmeter,
            'sensor_type'      => $sensor_type,
            'pulse_volume'     => $pulse_volume,
            'running_programs' => implode(', ', $running_programsV),
            'running_stations' => implode(', ', $running_stationsV),
            'last_program'     => $last_program,
            'last_station'     => $last_station,
        ];

        $station_infos = [];

        $snames = (array) $this->GetArrayElem($jdata, 'stations.snames', '');
        $ignore_rain = (array) $this->GetArrayElem($jdata, 'stations.ignore_rain', []);
        $ignore_sn1 = (array) $this->GetArrayElem($jdata, 'stations.ignore_sn1', []);
        $ignore_sn2 = (array) $this->GetArrayElem($jdata, 'stations.ignore_sn2', []);
        $stn_dis = (array) $this->GetArrayElem($jdata, 'stations.stn_dis', []);
        $stn_grp = (array) $this->GetArrayElem($jdata, 'stations.stn_grp', []);
        $stn_spe = (array) $this->GetArrayElem($jdata, 'stations.stn_spe', []);
        $mas = $this->GetArrayElem($jdata, 'options.mas', 0);
        $mas2 = $this->GetArrayElem($jdata, 'options.mas2', 0);
        $masop = (array) $this->GetArrayElem($jdata, 'stations.masop', []);
        $masop2 = (array) $this->GetArrayElem($jdata, 'stations.masop2', []);
        if ($controller_infos['has_flowmeter'] && $controller_infos['feature'] == 'ASB') {
            $stn_fas = (array) $this->GetArrayElem($jdata, 'stations.stn_fas', []);
            $stn_favg = (array) $this->GetArrayElem($jdata, 'stations.stn_favg', []);
        }

        for ($sid = 0; $sid < $station_count; $sid++) {
            for ($n = 0; $n < count($station_list); $n++) {
                if ($station_list[$n]['sid'] == $sid) {
                    break;
                }
            }
            if ($n == count($station_list)) {
                continue;
            }
            $station_entry = $station_list[$n];

            if ($sid == ($mas - 1)) {
                $master_id = 1;
            } elseif ($sid == ($mas2 - 1)) {
                $master_id = 2;
            } else {
                $master_id = 0;
            }

            if ($this->idx_in_bytes($sid, $masop)) {
                $assigned_master = 1;
            } elseif ($this->idx_in_bytes($sid, $masop2)) {
                $assigned_master = 2;
            } else {
                $assigned_master = 0;
            }

            $prV = [];
            for ($pid = 0; $pid < $nprogs; $pid++) {
                for ($n = 0; $n < count($program_list); $n++) {
                    if ($program_list[$n]['pid'] == $pid) {
                        break;
                    }
                }
                if ($n == count($program_list)) {
                    continue;
                }
                $program_entry = $program_list[$n];

                if ($program_entry['use'] == false) {
                    continue;
                }

                $duration = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.4', []);
                if ($duration[$sid] == 0) {
                    continue;
                }

                $flag = $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.0', '');

                $enabled = $this->bit_test($flag, 0) == true;

                if ($this->bit_test($flag, 6)) {
                    $starttime_type = self::$PROGRAM_STARTTIME_TYPE_FIXED;
                } else {
                    $starttime_type = self::$PROGRAM_STARTTIME_TYPE_REPEATING;
                }

                $start = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.3', []);
                $name = $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.5', '');

                $repV = [];
                if ($starttime_type == self::$PROGRAM_STARTTIME_TYPE_FIXED) {
                    for ($n = 0; $n < count($start); $n++) {
                        $min = $start[$n];
                        if ($min != -1) {
                            $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                        }
                    }
                } else {
                    $min = $start[0];
                    $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                    for ($n = 0; $n < $start[1]; $n++) {
                        $min += $start[2];
                        $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                    }
                }

                $s = $name . '[' . $this->seconds2duration($duration[$sid]) . ' @ ' . implode('/', $repV) . ']';
                if ($enabled == false) {
                    $s .= '(' . $this->Translate('disabled') . ')';
                }
                $prV[] = $s;
            }

            $info = implode(', ', $prV);

            $e = [
                'sid'             => $sid,
                'name'            => (isset($snames[$sid]) ? $snames[$sid] : ''),
                'group'           => (isset($stn_grp[$sid]) ? $this->Group2String($stn_grp[$sid]) : ''),
                'disabled'        => $this->idx_in_bytes($sid, $stn_dis),
                'ignore_rain'     => $this->idx_in_bytes($sid, $ignore_rain),
                'ignore_sn1'      => $this->idx_in_bytes($sid, $ignore_sn1),
                'ignore_sn2'      => $this->idx_in_bytes($sid, $ignore_sn2),
                'is_special'      => $this->idx_in_bytes($sid, $stn_spe),
                'master_id'       => $master_id,
                'is_master'       => $master_id != 0,
                'assigned_master' => $assigned_master,
                'use'             => $station_entry['use'],
                'info'            => $info,
            ];
            if ($controller_infos['has_flowmeter'] && $controller_infos['feature'] == 'ASB') {
                $e['flow_average'] = $stn_favg[$sid] / 100;
                $e['flow_threshold'] = $stn_fas[$sid] / 100;
            }
            $station_infos[] = $e;
        }

        $program_infos = [];
        for ($pid = 0; $pid < $nprogs; $pid++) {
            for ($n = 0; $n < count($program_list); $n++) {
                if ($program_list[$n]['pid'] == $pid) {
                    break;
                }
            }
            if ($n == count($program_list)) {
                continue;
            }
            $program_entry = $program_list[$n];

            $flag = $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.0', '');
            $days0 = $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.1', '');
            $days1 = $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.2', '');
            $start = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.3', []);
            $duration = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.4', []);
            $name = $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.5', '');
            $daterange = (array) $this->GetArrayElem($jdata, 'programs.pd.' . $pid . '.6', []);

            $enabled = $this->bit_test($flag, 0) == true;

            $weather_adjustment = $this->bit_test($flag, 1) == true;

            if ($this->bit_test($flag, 2) == true && $this->bit_test($flag, 3) == false) {
                $day_restriction = self::$PROGRAM_DAY_RESTRICTION_ODD;
            } elseif ($this->bit_test($flag, 2) == false && $this->bit_test($flag, 3) == true) {
                $day_restriction = self::$PROGRAM_DAY_RESTRICTION_EVEN;
            } else {
                $day_restriction = self::$PROGRAM_DAY_RESTRICTION_NONE;
            }

            if ($this->bit_test($flag, 4) && $this->bit_test($flag, 5)) {
                $schedule_type = self::$PROGRAM_SCHEDULE_TYPE_INTERVAL;
            } else {
                $schedule_type = self::$PROGRAM_SCHEDULE_TYPE_WEEKDAY;
            }

            if ($this->bit_test($flag, 6)) {
                $starttime_type = self::$PROGRAM_STARTTIME_TYPE_FIXED;
            } else {
                $starttime_type = self::$PROGRAM_STARTTIME_TYPE_REPEATING;
            }

            $enable_date_range = $this->bit_test($flag, 7) == true;

            $repV = [];
            if ($starttime_type == self::$PROGRAM_STARTTIME_TYPE_FIXED) {
                for ($n = 0; $n < count($start); $n++) {
                    $min = $start[$n];
                    if ($min != -1) {
                        $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                    }
                }
            } else {
                $min = $start[0];
                $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                for ($n = 0; $n < $start[1]; $n++) {
                    $min += $start[2];
                    $repV[] = sprintf('%02d:%02d', ($min / 60), ($min % 60));
                }
            }

            $stationsV = [];
            for ($sid = 0; $sid < $station_count; $sid++) {
                for ($n = 0; $n < count($station_list); $n++) {
                    if ($station_list[$n]['sid'] == $sid) {
                        break;
                    }
                }
                if ($n == count($station_list)) {
                    continue;
                }
                $station_entry = $station_list[$n];

                if ($station_entry['use'] == false) {
                    continue;
                }

                if ($duration[$sid] == 0) {
                    continue;
                }
                $s = $snames[$sid] . '[' . $this->seconds2duration($duration[$sid]) . ']';
                if ($this->idx_in_bytes($sid, $stn_dis)) {
                    $s .= '(' . $this->Translate('disabled') . ')';
                }
                $stationsV[] = $s;
            }

            $info = $this->TranslateFormat('Start at {$rep} with: {$stations}', ['{$rep}' => implode('/', $repV), '{$stations}' => implode(', ', $stationsV)]);

            /*
                $fpr = (($fpr1 << 5) + $fpr0) ;

                [daterange] => Array
                    (
                        [0] => 0
                        [1] => 33
                        [2] => 415
                    )


             */

            $e = [
                'pid'                => $pid,
                'name'               => $name,
                'enabled'            => $enabled,
                'weather_adjustment' => $weather_adjustment,
                'day_restriction'    => $day_restriction,
                'schedule_type'      => $schedule_type,
                'starttime_type'     => $starttime_type,
                'enable_date_range'  => $enable_date_range,
                'daterange'          => [
                    'from' => $this->DecodeDaterange($daterange[1]),
                    'to'   => $this->DecodeDaterange($daterange[2]),
                ],
                'days0'              => $days0,
                'days1'              => $days1,
                'start'              => $start,
                'duration'           => $duration,
                'total_duration'     => array_sum($duration),
                'use'                => $program_entry['use'],
                'info'               => $info,
            ];
            $program_infos[] = $e;
        }

        $this->SendDebug(__FUNCTION__, 'controller_infos=' . print_r($controller_infos, true), 0);
        $this->WriteAttributeString('controller_infos', json_encode($controller_infos));

        $this->SendDebug(__FUNCTION__, 'station_infos=' . print_r($station_infos, true), 0);
        $this->WriteAttributeString('station_infos', json_encode($station_infos));

        $this->SendDebug(__FUNCTION__, 'program_infos=' . print_r($program_infos, true), 0);
        $this->WriteAttributeString('program_infos', json_encode($program_infos));
    }

    private function idx_in_bytes($idx, $val)
    {
        $byte = floor($idx / 8);
        if ($byte >= count($val)) {
            return false;
        }
        $bit = $idx % 8;
        return $this->bit_test($val[$byte], $bit);
    }

    private function RetriveConfiguration()
    {
        if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
            $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
            return;
        }

        $old_station_list = (array) @json_decode($this->ReadPropertyString('station_list'), true);
        $old_sensor_list = (array) @json_decode($this->ReadPropertyString('sensor_list'), true);
        $old_program_list = (array) @json_decode($this->ReadPropertyString('program_list'), true);

        $data = $this->do_HttpRequest('ja', []);
        if ($data == false) {
            IPS_SemaphoreLeave($this->SemaphoreID);
            return;
        }
        $ja_data = json_decode($data, true);
        $a = (array) $this->GetArrayElem($ja_data, 'stations', []);
        $this->SendDebug(__FUNCTION__, 'stations=' . print_r($a, true), 0);
        $a = (array) $this->GetArrayElem($ja_data, 'options', []);
        $this->SendDebug(__FUNCTION__, 'options=' . print_r($a, true), 0);
        $a = (array) $this->GetArrayElem($ja_data, 'programs', []);
        $this->SendDebug(__FUNCTION__, 'programs=' . print_r($a, true), 0);

        $nbrd = $this->GetArrayElem($ja_data, 'settings.nbrd', 1);
        $station_count = $nbrd * 8;

        $has_special = false;
        $stn_spe = (array) $this->GetArrayElem($ja_data, 'stations.stn_spe', []);
        for ($sid = 0; $sid < $station_count; $sid++) {
            if ($this->idx_in_bytes($sid, $stn_spe)) {
                $has_special = true;
            }
        }

        if ($has_special) {
            $data = $this->do_HttpRequest('je', []);
            if ($data == false) {
                IPS_SemaphoreLeave($this->SemaphoreID);
                return;
            }
            $special_stations = json_decode($data, true);
        } else {
            $special_stations = [];
        }
        $this->SendDebug(__FUNCTION__, 'special_stations=' . print_r($special_stations, true), 0);

        $remote_extension = (bool) $this->GetArrayElem($ja_data, 'options.re', false);

        $station_list = [];
        $ignore_rain = (array) $this->GetArrayElem($ja_data, 'stations.ignore_rain', []);
        $ignore_sn1 = (array) $this->GetArrayElem($ja_data, 'stations.ignore_sn1', []);
        $ignore_sn2 = (array) $this->GetArrayElem($ja_data, 'stations.ignore_sn2', []);
        $stn_dis = (array) $this->GetArrayElem($ja_data, 'stations.stn_dis', []);
        $stn_spe = (array) $this->GetArrayElem($ja_data, 'stations.stn_spe', []);
        $mas = $this->GetArrayElem($ja_data, 'options.mas', 0);
        $mas2 = $this->GetArrayElem($ja_data, 'options.mas2', 0);
        for ($sid = 0; $sid < $station_count; $sid++) {
            $use = true;
            foreach ($old_station_list as $old_station) {
                if ($old_station['sid'] == $sid) {
                    $use = $old_station['use'];
                    break;
                }
            }

            $sname = $this->GetArrayElem($ja_data, 'stations.snames.' . $sid, '');
            $stn_grp = $this->GetArrayElem($ja_data, 'stations.stn_grp.' . $sid, 0);
            $infos = [];

            if ($this->idx_in_bytes($sid, $stn_dis)) {
                $infos[] = $this->Translate('Disabled');
            }
            if ($sid == ($mas - 1)) {
                $infos[] = $this->Translate('Master valve') . ' 1';
            }
            if ($sid == ($mas2 - 1)) {
                $infos[] = $this->Translate('Master valve') . ' 2';
            }

            if ($this->idx_in_bytes($sid, $ignore_rain)) {
                $infos[] = $this->Translate('ignore rain delay');
            }
            if ($this->idx_in_bytes($sid, $ignore_sn1)) {
                $snt = $this->GetArrayElem($ja_data, 'options.sn1t', 0);
                if ($snt == self::$SENSOR_TYPE_FLOW) {
                    $infos[] = $this->Translate('no flow measuring');
                } else {
                    $infos[] = $this->Translate('ignore sensor 1');
                }
            }
            if ($this->idx_in_bytes($sid, $ignore_sn2)) {
                $snt = $this->GetArrayElem($ja_data, 'options.sn2t', 0);
                if ($snt == self::$SENSOR_TYPE_FLOW) {
                    $infos[] = $this->Translate('no flow measuring');
                } else {
                    $infos[] = $this->Translate('ignore sensor 2');
                }
            }
            if ($this->idx_in_bytes($sid, $stn_spe)) {
                $st = $this->GetArrayElem($special_stations, $sid . '.st', 0);
                switch ($st) {
                    case 0: // local
                        $interface = sprintf(self::$STATION_PREFIX . '%02d', $sid + 1);
                        break;
                    case 1: // RF (radio frequency) station
                        $interface = $this->Translate('RF');
                        break;
                    case 2: // remote station (IP)
                        $sd = $this->GetArrayElem($special_stations, $sid . '.sd', 0);
                        $ip = strval(hexdec(substr($sd, 0, 2))) . '.' . strval(hexdec(substr($sd, 2, 2))) . '.' . strval(hexdec(substr($sd, 4, 2))) . '.' . strval(hexdec(substr($sd, 6, 2)));
                        $port = hexdec(substr($sd, 8, 4));
                        $rsid = hexdec(substr($sd, 12, 2));
                        $interface = $this->Translate('remote') . sprintf(' [S%02d]', $sid + 1);
                        break;
                    case 3: // GPIO station
                        $interface = $this->Translate('GPIO');
                        break;
                    case 4: // HTTP station
                        $interface = $this->Translate('HTTP');
                        break;
                    case 5: // HTTPS stations
                        $interface = $this->Translate('HTTPS');
                        break;
                    case 6: // remote station (OTC)
                        $interface = $this->Translate('remote (OTC)');
                        break;
                    default:
                        break;
                }
            } else {
                $interface = sprintf(self::$STATION_PREFIX . '%02d', $sid + 1);
            }

            $station_list[] = [
                'sid'       => $sid,
                'name'      => $sname,
                'group'     => $this->Group2String($stn_grp),
                'interface' => $interface,
                'info'      => implode(', ', $infos),
                'use'       => $use,
            ];
        }

        if ($station_list != (array) @json_decode($this->ReadPropertyString('station_list'), true)) {
            $this->SendDebug(__FUNCTION__, 'update station_list=' . print_r($station_list, true), 0);
            $this->UpdateFormField('station_list', 'values', json_encode($station_list));
            $this->UpdateFormField('station_list', 'rowCount', count($station_list) > 0 ? count($station_list) : 1);
        } else {
            $this->SendDebug(__FUNCTION__, 'unchanges station_list=' . print_r($station_list, true), 0);
        }

        $sensor_list = [];
        if ($remote_extension == false) {
            for ($sni = 1; $sni <= 2; $sni++) {
                $use = true;
                foreach ($old_sensor_list as $old_sensor) {
                    if ($old_sensor['sni'] == $sni) {
                        $use = $old_sensor['use'];
                        break;
                    }
                }

                $snt = $this->GetArrayElem($ja_data, 'options.sn' . $sni . 't', 0);
                switch ($snt) {
                    case self::$SENSOR_TYPE_RAIN:
                        $sno = $this->GetArrayElem($ja_data, 'options.sn' . $sni . 'o', 0);
                        $sensor_list[] = [
                            'sni'  => $sni,
                            'type' => $snt,
                            'name' => $this->SensorType2String($snt),
                            'info' => $this->Translate('Contact variant') . ': ' . $this->SensorType2String($sno),
                            'use'  => $use,
                        ];
                        break;
                    case self::$SENSOR_TYPE_FLOW:
                        $fpr0 = $this->GetArrayElem($ja_data, 'options.fpr0', 0);
                        $fpr1 = $this->GetArrayElem($ja_data, 'options.fpr1', 0);
                        $fpr = (($fpr1 << 8) + $fpr0) / 100.0;
                        $sensor_list[] = [
                            'sni'  => $sni,
                            'type' => $snt,
                            'name' => $this->SensorType2String($snt),
                            'info' => $this->TranslateFormat('Resolution: {$fpr} l/pulse', ['{$fpr}' => $fpr]),
                            'use'  => $use,
                        ];
                        break;
                    case self::$SENSOR_TYPE_SOIL:
                        $sno = $this->GetArrayElem($ja_data, 'options.sn' . $sni . 'o', 0);
                        $sensor_list[] = [
                            'sni'  => $sni,
                            'type' => $snt,
                            'name' => $this->SensorType2String($snt),
                            'use'  => $use,
                            'info' => $this->Translate($sno ? 'normally open' : 'normally closed'),
                        ];
                        break;
                    default:
                        break;
                }
            }

            if ($sensor_list != (array) @json_decode($this->ReadPropertyString('sensor_list'), true)) {
                $this->SendDebug(__FUNCTION__, 'update sensor_list=' . print_r($sensor_list, true), 0);
                $this->UpdateFormField('sensor_list', 'values', json_encode($sensor_list));
                $this->UpdateFormField('sensor_list', 'rowCount', count($sensor_list) > 0 ? count($sensor_list) : 1);
            } else {
                $this->SendDebug(__FUNCTION__, 'unchanges sensor_list=' . print_r($sensor_list, true), 0);
            }
            $this->UpdateFormField('sensor_list', 'visible', true);
            $this->UpdateFormField('sensor_errmsg', 'visible', false);
        } else {
            $this->UpdateFormField('sensor_list', 'visible', false);
            $this->UpdateFormField('sensor_errmsg', 'visible', true);
        }

        $program_list = [];
        if ($remote_extension == false) {
            $nprogs = $this->GetArrayElem($ja_data, 'programs.nprogs', 0);
            for ($pid = 0; $pid < $nprogs; $pid++) {
                $use = true;
                foreach ($old_program_list as $old_program) {
                    if ($old_program['pid'] == $pid) {
                        $use = $old_program['use'];
                        break;
                    }
                }

                $flag = $this->GetArrayElem($ja_data, 'programs.pd.' . $pid . '.0', '');

                $enabled = $this->bit_test($flag, 0) == true;

                $weather_adjustment = $this->bit_test($flag, 1) == true;

                $name = $this->GetArrayElem($ja_data, 'programs.pd.' . $pid . '.5', '');

                $infos = [];
                if ($enabled == false) {
                    $infos[] = $this->Translate('Disabled');
                }
                if ($weather_adjustment) {
                    $infos[] = $this->Translate('Weather adjustment');
                }
                $duration = (array) $this->GetArrayElem($ja_data, 'programs.pd.' . $pid . '.4', []);
                $total_duration = array_sum($duration);
                $infos[] = $this->TranslateFormat('Total duration is {$total_duration}', ['{$total_duration}' => $this->seconds2duration($total_duration)]);

                $program_list[] = [
                    'pid'  => $pid,
                    'name' => $name,
                    'info' => implode(', ', $infos),
                    'use'  => $use,
                ];
            }

            if ($program_list != (array) @json_decode($this->ReadPropertyString('program_list'), true)) {
                $this->SendDebug(__FUNCTION__, 'update program_list=' . print_r($program_list, true), 0);
                $this->UpdateFormField('program_list', 'values', json_encode($program_list));
                $this->UpdateFormField('program_list', 'rowCount', count($program_list) > 0 ? count($program_list) : 1);
            } else {
                $this->SendDebug(__FUNCTION__, 'unchanged program_list=' . print_r($program_list, true), 0);
            }
            $this->UpdateFormField('program_list', 'visible', true);
            $this->UpdateFormField('program_errmsg', 'visible', false);
        } else {
            $this->UpdateFormField('program_list', 'visible', true);
            $this->UpdateFormField('program_errmsg', 'visible', false);
        }

        $this->SaveInfos($ja_data);

        IPS_SemaphoreLeave($this->SemaphoreID);
    }

    public function ReceiveData($data)
    {
        $this->SendDebug(__FUNCTION__, 'data=' . $data, 0);

        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $jdata = json_decode($data, true);
        $topic = isset($jdata['Topic']) ? $jdata['Topic'] : '';
        $mqtt_topic = $this->ReadPropertyString('mqtt_topic');
        $topic = substr($topic, strlen($mqtt_topic) + 1);
        $payload = isset($jdata['Payload']) ? $jdata['Payload'] : '';
        switch ($topic) {
            case 'availability':
                break;
            default:
                $payload = (array) @json_decode($payload, true);
                break;
        }

        $this->SendDebug(__FUNCTION__, 'topic=' . $topic . ', payload=' . print_r($payload, true), 0);

        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);
        $station_infos = (array) @json_decode($this->ReadAttributeString('station_infos'), true);

        $this->CheckDailyValues();

        // topic=station/0, payload=Array<LF>(<LF>    [state] => 1<LF>    [duration] => 300<LF>)<LF>
        // topic=station/0, payload=Array<LF>(<LF>    [state] => 0<LF>    [duration] => 300<LF>    [flow] => 0<LF>)<LF>
        if (preg_match('#^station/(\d+)$#', $topic, $r)) {
            $sid = $r[1];

            if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
                $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
                return;
            }

            $station_list = (array) @json_decode($this->ReadPropertyString('station_list'), true);
            for ($n = 0; $n < count($station_list); $n++) {
                if ($station_list[$n]['sid'] == $sid) {
                    break;
                }
            }
            if ($n == count($station_list)) {
                $use = false;
            } else {
                $station_entry = $station_list[$n];
                $use = $station_entry['use'];
            }

            $station_info = false;
            for ($i = 0; $i < count($station_infos); $i++) {
                if ($station_infos[$i]['sid'] == $sid) {
                    $station_info = $station_infos[$i];
                    break;
                }
            }
            if ($station_info == false) {
                $this->SendDebug(__FUNCTION__, 'no station_info for sid=' . $sid, 0);
                $use = false;
            }

            if ($use) {
                $post = '_' . ($sid + 1);

                $master_id = $this->GetArrayElem($station_info, 'master_id', 0);

                $fnd = true;
                $state = $this->GetArrayElem($payload, 'state', 0, $fnd);
                if ($fnd) {
                    $st = $state ? self::$STATION_STATE_WATERING : self::$STATION_STATE_READY;
                    if ($this->Use4Ident('StationState', $sid)) {
                        $this->SendDebug(__FUNCTION__, '... StationState' . $post . ' => ' . $st, 0);
                        $this->SetValue('StationState' . $post, $st);
                    }
                }

                if ($master_id == 0) {
                    $has_duration = false;
                    $duration = $this->GetArrayElem($payload, 'duration', 0, $has_duration);
                    if ($has_duration) {
                        if ($this->Use4Ident('StationTimeLeft', $sid)) {
                            $this->SendDebug(__FUNCTION__, '... StationTimeLeft' . $post . ' => ' . $duration, 0);
                            $this->SetValue('StationTimeLeft' . $post, $duration);
                        }
                    }

                    $cur_run = $this->GetCurRun($sid);
                    if ($state) {
                        if ($cur_run === false) {
                            $cur_run = [
                                'sid'   => $sid,
                                'start' => time(),
                            ];
                            if ($this->HasWaterMeter()) {
                                $cur_run['water_counter'] = $this->GetWaterMeter();
                            }
                        }
                        if ($this->Use4Ident('WaterFlowrate')) {
                            $cur_run['flow'] = $this->GetValue('WaterFlowrate');
                        }
                        if ($has_duration) {
                            $cur_run['left'] = $duration;
                        }

                        $this->SetCurRun($cur_run);
                    } else {
                        if ($has_duration) {
                            if ($this->Use4Ident('StationLastRun', $sid)) {
                                $last_run = time() - $duration;
                                $this->SendDebug(__FUNCTION__, '... StationLastRun' . $post . ' => ' . date('d.m.y H:i:s', $last_run), 0);
                                $this->SetValue('StationLastRun' . $post, $last_run);
                            }

                            if ($this->Use4Ident('StationLastDuration', $sid)) {
                                $this->SendDebug(__FUNCTION__, '... StationLastDuration' . $post . ' => ' . $duration, 0);
                                $this->SetValue('StationLastDuration' . $post, $duration);
                            }

                            if ($this->Use4Ident('DailyDuration')) {
                                $old_daily_duration = $this->GetValue('DailyDuration');
                                $daily_duration = $old_daily_duration + $duration;
                                $this->SendDebug(__FUNCTION__, '... DailyDuration => ' . $daily_duration . ' (old=' . $old_daily_duration . ')', 0);
                                $this->SetValue('DailyDuration', $daily_duration);
                            }

                            if ($this->Use4Ident('TotalDuration')) {
                                $old_total_duration = $this->GetValue('TotalDuration');
                                $total_duration = $old_total_duration + $duration;
                                $this->SendDebug(__FUNCTION__, '... TotalDuration => ' . $total_duration . ' (old=' . $old_total_duration . ')', 0);
                                $this->SetValue('TotalDuration', $total_duration);
                            }

                            if ($this->Use4Ident('StationTotalDuration', $sid)) {
                                $old_total_duration = $this->GetValue('StationTotalDuration' . $post);
                                $total_duration = $old_total_duration + $duration;
                                $this->SendDebug(__FUNCTION__, '... StationTotalDuration' . $post . ' => ' . $total_duration . ' (old=' . $old_total_duration . ')', 0);
                                $this->SetValue('StationTotalDuration' . $post, $total_duration);
                            }

                            if ($this->Use4Ident('StationDailyDuration', $sid)) {
                                $old_daily_duration = $this->GetValue('StationDailyDuration' . $post);
                                $daily_duration = $old_daily_duration + $duration;
                                $this->SendDebug(__FUNCTION__, '... StationDailyDuration' . $post . ' => ' . $daily_duration . ' (old=' . $old_daily_duration . ')', 0);
                                $this->SetValue('StationDailyDuration' . $post, $daily_duration);
                            }
                        }
                        if ($cur_run !== false) {
                            if ($this->HasWaterMeter()) {
                                if ($cur_run !== false) {
                                    $water_counter = $this->GetWaterMeter();
                                    $last_water_counter = $cur_run['water_counter'];
                                    $usage = round((float) $water_counter - (float) $last_water_counter, self::$PRECISION_USAGE);
                                    $has_usage = true;
                                    if ($has_duration && $duration > 0) {
                                        $flow = round($usage / (float) ($duration / 60), self::$PRECISION_FLOW);
                                    } else {
                                        $flow = 0;
                                    }
                                    $this->SendDebug(__FUNCTION__, 'water_counter=' . $last_water_counter . ' ... ' . $water_counter . ' => usage=' . $usage . ' l, real flow=' . $flow . ' l/min', 0);
                                } else {
                                    $usage = 0;
                                    $flow = 0;
                                    $has_usage = false;
                                }
                            } else {
                                $flow = $this->GetArrayElem($payload, 'flow', 0, $fnd);
                                if ($fnd) {
                                    if ($has_duration && $duration > 0) {
                                        $usage = round($flow * (float) ($duration / 60), self::$PRECISION_USAGE);
                                        $has_usage = true;
                                    } else {
                                        $usage = 0;
                                    }
                                    $this->SendDebug(__FUNCTION__, 'flow=' . $flow . ', duration=' . $duration . ' => usage=' . $usage . ' l', 0);
                                } else {
                                    $usage = 0;
                                    $flow = 0;
                                    $has_usage = false;
                                }
                            }
                            if ($has_usage) {
                                if ($this->Use4Ident('TotalWaterUsage')) {
                                    $old_total_usage = $this->GetValue('TotalWaterUsage');
                                    $total_usage = round($old_total_usage + $usage, self::$PRECISION_USAGE);
                                    $this->SendDebug(__FUNCTION__, '... TotalWaterUsage => ' . $total_usage . ' (old=' . $old_total_usage . ')', 0);
                                    $this->SetValue('TotalWaterUsage', $total_usage);
                                }

                                if ($this->Use4Ident('DailyWaterUsage')) {
                                    $old_daily_usage = $this->GetValue('DailyWaterUsage');
                                    $daily_usage = round($old_daily_usage + $usage, self::$PRECISION_USAGE);
                                    $this->SendDebug(__FUNCTION__, '... DailyWaterUsage => ' . $daily_usage . ' (old=' . $old_daily_usage . ')', 0);
                                    $this->SetValue('DailyWaterUsage', $daily_usage);
                                }

                                if ($this->Use4Ident('StationWaterUsage', $sid)) {
                                    $this->SendDebug(__FUNCTION__, '... StationWaterUsage' . $post . ' => ' . $usage, 0);
                                    $this->SetValue('StationWaterUsage' . $post, $usage);
                                }

                                if ($this->Use4Ident('StationTotalWaterUsage', $sid)) {
                                    $old_total_usage = $this->GetValue('StationTotalWaterUsage' . $post);
                                    $total_usage = round($old_total_usage + $usage, self::$PRECISION_USAGE);
                                    $this->SendDebug(__FUNCTION__, '... StationTotalWaterUsage' . $post . ' => ' . $total_usage . ' (old=' . $old_total_usage . ')', 0);
                                    $this->SetValue('StationTotalWaterUsage' . $post, $total_usage);
                                }

                                if ($this->Use4Ident('StationDailyWaterUsage', $sid)) {
                                    $old_daily_usage = $this->GetValue('StationDailyWaterUsage' . $post);
                                    $daily_usage = round($old_daily_usage + $usage, self::$PRECISION_USAGE);
                                    $this->SendDebug(__FUNCTION__, '... StationDailyWaterUsage' . $post . ' => ' . $daily_usage . ' (old=' . $old_daily_usage . ')', 0);
                                    $this->SetValue('StationDailyWaterUsage' . $post, $daily_usage);
                                }
                            }

                            $start = $cur_run['start'];
                            $end = $start + $duration;
                            $this->AddLog4Station($sid, $start, $end, $duration, $flow, $usage);

                            $this->DelCurRun($sid);
                        }
                    }
                }
            }

            IPS_SemaphoreLeave($this->SemaphoreID);
        }

        // topic=sensor/flow, payload=Array<LF>(<LF>    [count] => 0<LF>    [volume] => 0<LF>)<LF>
        if (preg_match('#^sensor/flow$#', $topic, $r)) {
        }

        // topic=sensor1, payload=Array<LF>(<LF>    [state] => 1<LF>)<LF>
        // topic=sensor2, payload=Array<LF>(<LF>    [state] => 1<LF>)<LF>
        if (preg_match('#^sensor(\d)$#', $topic, $r)) {
            $sni = $r[1];

            if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
                $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
                return;
            }

            $fnd = true;
            $state = $this->GetArrayElem($payload, 'state', 0, $fnd);
            if ($fnd) {
                $snt = $controller_infos['sensor_type'][$sni];
                if (in_array($snt, [self::$SENSOR_TYPE_RAIN, self::$SENSOR_TYPE_SOIL])) {
                    $this->SendDebug(__FUNCTION__, '... SensorState_' . $sni . ' (state)=' . $state, 0);
                    $this->SetValue('SensorState_' . $sni, $state);
                }
            }

            IPS_SemaphoreLeave($this->SemaphoreID);
        }

        // topic=station/0/alert/flow, payload=Array<LF>(<LF>    [flow_rate] => %f<LF>    [duration] => 0<LF>    [alert_setpoint] => 0<LF>)<LF>
        if (preg_match('#^station/(\d+)/alert/flow$#', $topic, $r)) {
            $sid = $r[1];

            if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
                $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
                return;
            }

            $params = [
                'type'    => 'flow',
                'topic'   => $topic,
                'payload' => json_encode($payload),
                'sid'     => $sid,
            ];

            $station_list = (array) @json_decode($this->ReadPropertyString('station_list'), true);
            for ($n = 0; $n < count($station_list); $n++) {
                if ($station_list[$n]['sid'] == $sid) {
                    $params['station'] = $station_list[$n]['name'];
                    break;
                }
            }

            $params['flow_rate'] = $this->GetArrayElem($payload, 'flow_rate', 0);
            $params['duration'] = $this->GetArrayElem($payload, 'duration', 0);
            $params['alert_setpoint'] = $this->GetArrayElem($payload, 'alert_setpoint', 0);

            $s = 'Current flow rate of station "{$station}" is {$flow_rate} l/min, limit is {$alert_setpoint}';
            $p = [
                '{$station}'        => $params['station'],
                '{$flow_rate}'      => $params['flow_rate'],
                '{$alert_setpoint}' => $params['alert_setpoint'],
            ];
            $msg = $this->TranslateFormat($s, $p);

            $this->Notify($msg, 'alert', $params);

            IPS_SemaphoreLeave($this->SemaphoreID);
        }

        // topic=monitoring, payload=Array<LF>(<LF>    [warning] => ""<LF>    [prio] => 0<LF>    [value] => 0.0<LF>)<LF>
        if (preg_match('#^monitoring$#', $topic, $r)) {
            if (IPS_SemaphoreEnter($this->SemaphoreID, self::$semaphoreTM) == false) {
                $this->SendDebug(__FUNCTION__, 'unable to lock sempahore ' . $this->SemaphoreID, 0);
                return;
            }

            $params = [
                'type'    => 'monitoring',
                'topic'   => $topic,
                'payload' => json_encode($payload),
            ];

            $params['warning'] = $this->GetArrayElem($payload, 'warning', 0);
            switch ($this->GetArrayElem($payload, 'prio', 0)) {
                case 0:
                    $params['prio'] = $this->Translate('low');
                    break;
                case 1:
                default:
                    $params['prio'] = $this->Translate('mid');
                    break;
                case 2:
                    $params['prio'] = $this->Translate('high');
                    break;
            }
            $params['value'] = $this->GetArrayElem($payload, 'value', 0);

            $s = 'Warning {$warning} with priority {$prio}, current value {$value}';
            $p = [
                '{$warning}' => $params['warning'],
                '{$prio}'    => $params['prio'],
                '{$value}'   => $params['value'],
            ];
            $msg = $this->TranslateFormat($s, $p);

            $this->Notify($msg, 'warning', $params);

            IPS_SemaphoreLeave($this->SemaphoreID);
        }

        // topic=system, payload=Array<LF>(<LF>    [state] => "started"<LF>)<LF>
        // topic=raindelay, payload=Array<LF>(<LF>    [state] => 0<LF>)<LF>
        // tpoic=weather, payload=Array<LF>(<LF>    [water level] => 0<LF>)<LF>

        // topic=availability, payload=online
        // topic=availability, payload=offline

        // topic=analogsensor/Luftfeuchte, payload=Array<LF>(<LF>    [nr] => 2<LF>    [type] => 90<LF>    [data_o] => 1<LF>    [time] => 1732540919<LF>    [value] => 68<LF>    [unit] => %<LF>)<LF>

        $this->MaintainStatus(IS_ACTIVE);
    }

    protected function SendVariables()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $variables_mqtt_topic = $this->ReadPropertyString('variables_mqtt_topic');

        $variable_list = (array) @json_decode($this->ReadPropertyString('variable_list'), true);
        $payload = [];
        foreach ($variable_list as $variable) {
            $varID = $variable['varID'];
            if (IPS_VariableExists($varID) == false) {
                continue;
            }
            $payload[$variable['mqtt_filter']] = GetValue($varID);
        }
        $r = $this->PublishToOpenSprinkler($variables_mqtt_topic, $payload);

        $this->SendDebug(__FUNCTION__, $this->PrintTimer('SendVariables'), 0);
    }

    protected function PublishToOpenSprinkler($topic, $payload)
    {
        $jdata = [
            'DataID'           => '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}',
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => $topic,
            'Payload'          => json_encode($payload),
        ];

        $data = json_encode($jdata);
        $r = $this->SendDataToParent($data);
        $this->SendDebug(__FUNCTION__, 'SendDataToParent(' . $data . ') => ' . $r, 0);
        return $r;
    }

    public function ForwardData($data)
    {
        $this->SendDebug(__FUNCTION__, 'data=' . $data, 0);

        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $jdata = @json_decode($data, true);
        $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
    }

    private function LocalRequestAction($ident, $value)
    {
        $r = true;

        switch ($ident) {
            case 'QueryStatus':
                $this->QueryStatus();
                break;
            case 'SendVariables':
                $this->SendVariables();
                break;
            case 'GetControllerLog':
                $this->GetControllerLog(0, 0);
                break;
            case 'RetriveConfiguration':
                $this->RetriveConfiguration();
                break;
            case 'AdjustVariablenames':
                $this->AdjustVariablenames();
                break;
            default:
                $r = false;
                break;
        }
        return $r;
    }

    public function RequestAction($ident, $value)
    {
        if ($this->LocalRequestAction($ident, $value)) {
            return;
        }
        if ($this->CommonRequestAction($ident, $value)) {
            return;
        }

        if ($this->GetStatus() == IS_INACTIVE) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return;
        }

        $this->SendDebug(__FUNCTION__, 'ident=' . $ident . ', value=' . $value, 0);

        $_ident = explode('_', $ident);
        $ident_base = $_ident[0];
        $ident_extent = isset($_ident[1]) ? $_ident[1] : '';

        $r = false;
        $shortInterval = 500;
        $longInterval = 1000;
        $queryInterval = 0;
        switch ($ident_base) {
            case 'ControllerEnabled':
                if ($this->Use4Ident('ControllerEnabled')) {
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                    $r = $this->SetControllerEnabled((bool) $value);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'WateringLevel':
                if ($this->Use4Ident('WateringLevel')) {
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                    $r = $this->SetWateringLevel((int) $value);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'RainDelayAction':
                if ($this->Use4Ident('RainDelay')) {
                    $d = $this->GetValue('RainDelayDays');
                    $h = $this->GetValue('RainDelayHours');
                    $t = $d * 24 + $h;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', t=' . $t . 'h', 0);
                    $r = $this->SetRainDelay((int) $value, $t);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'StopAllStations':
                if ($this->Use4Ident('StopAllStations')) {
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                    $r = $this->StopAllStations();
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'PauseQueueAction':
                if ($this->Use4Ident('PauseQueue')) {
                    $h = $this->GetValue('PauseQueueHours');
                    $m = $this->GetValue('PauseQueueMinutes');
                    $s = $this->GetValue('PauseQueueSeconds');
                    $t = ($h * 60 + $m) * 60 + $s;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', t=' . $t . 's', 0);
                    $r = $this->PauseQueue((int) $value, $t);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'StationSelection':
                if ($this->Use4Ident('StationSelection')) {
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                    $r = $this->SetStationSelection((int) $value);
                }
                break;
            case 'StationDisabled':
                if ($this->Use4Ident('StationDisabled') && $this->Use4Ident('StationSelection')) {
                    $sid = $this->GetValue('StationSelection');
                    if ($sid == 0) {
                        break;
                    }
                    $sid--;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', sid=' . $sid, 0);
                    $r = $this->SetStationDisabled($sid, (bool) $value);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'StationIgnoreRain':
                if ($this->Use4Ident('StationIgnoreRain') && $this->Use4Ident('StationSelection')) {
                    $sid = $this->GetValue('StationSelection');
                    if ($sid == 0) {
                        break;
                    }
                    $sid--;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', sid=' . $sid, 0);
                    $r = $this->SetStationIgnoreRain($sid, (bool) $value);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'StationIgnoreSensor1':
                if ($this->Use4Ident('StationIgnoreSensor1') && $this->Use4Ident('StationSelection')) {
                    $sid = $this->GetValue('StationSelection');
                    if ($sid == 0) {
                        break;
                    }
                    $sid--;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', sid=' . $sid, 0);
                    $r = $this->SetStationIgnoreSensor1($sid, (bool) $value);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'StationIgnoreSensor2':
                if ($this->Use4Ident('StationIgnoreSensor2') && $this->Use4Ident('StationSelection')) {
                    $sid = $this->GetValue('StationSelection');
                    if ($sid == 0) {
                        break;
                    }
                    $sid--;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', sid=' . $sid, 0);
                    $r = $this->SetStationIgnoreSensor2($sid, (bool) $value);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'StationFlowThreshold':
                if ($this->Use4Ident('StationFlowThreshold') && $this->Use4Ident('StationSelection')) {
                    $sid = $this->GetValue('StationSelection');
                    if ($sid == 0) {
                        break;
                    }
                    $sid--;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', sid=' . $sid, 0);
                    $r = $this->SetStationFlowThreshold($sid, (float) $value);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'StationStartManually':
                if ($this->Use4Ident('StationStartManually') && $this->Use4Ident('StationSelection')) {
                    $sid = $this->GetValue('StationSelection');
                    if ($sid == 0) {
                        break;
                    }
                    $sid--;
                    $h = $this->GetValue('StationStartManuallyHours');
                    $m = $this->GetValue('StationStartManuallyMinutes');
                    $s = $this->GetValue('StationStartManuallySeconds');
                    $t = ($h * 60 + $m) * 60 + $s;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', sid=' . $sid . ', timer=' . $t . 's', 0);
                    $r = $this->StationStartManually($sid, (int) $value, $t);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'ProgramSelection':
                if ($this->Use4Ident('ProgramSelection')) {
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                    $r = $this->SetProgramSelection((int) $value);
                }
                break;
            case 'ProgramEnabled':
                if ($this->Use4Ident('ProgramEnabled') && $this->Use4Ident('ProgramSelection')) {
                    $pid = $this->GetValue('ProgramSelection');
                    if ($pid == 0) {
                        break;
                    }
                    $pid--;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', pid=' . $pid, 0);
                    $r = $this->SetProgramEnabled($pid, (bool) $value);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'ProgramWeatherAdjust':
                if ($this->Use4Ident('ProgramWeatherAdjust') && $this->Use4Ident('ProgramSelection')) {
                    $pid = $this->GetValue('ProgramSelection');
                    if ($pid == 0) {
                        break;
                    }
                    $pid--;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', pid=' . $pid, 0);
                    $r = $this->SetProgramWeatherAdjust($pid, (bool) $value);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'ProgramStartManually':
                if ($this->Use4Ident('ProgramStartManually') && $this->Use4Ident('ProgramSelection')) {
                    $pid = $this->GetValue('ProgramSelection');
                    if ($pid == 0) {
                        break;
                    }
                    $pid--;
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value . ', pid=' . $pid, 0);
                    $r = $this->ProgramStartManually($pid, (int) $value == self::$PROGRAM_START_WITH_WEATHER);
                    if ($r) {
                        $queryInterval = $shortInterval;
                    }
                }
                break;
            case 'RainDelayDays':
            case 'RainDelayHours':
            case 'PauseQueueHours':
            case 'PauseQueueMinutes':
            case 'PauseQueueSeconds':
            case 'StationStartManuallyHours':
            case 'StationStartManuallyMinutes':
            case 'StationStartManuallySeconds':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                $r = true;
                break;
            case 'IrrigationDuration':
                $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                // $r = true;
                break;
            case 'SummaryDays':
            case 'SummaryGroupBy':
                if ($this->Use4Ident('Summary')) {
                    $this->SendDebug(__FUNCTION__, $ident . '=' . $value, 0);
                    $this->SetValue($ident, $value);

                    $days = (int) $this->GetValue('SummaryDays');
                    $groupBy = (int) $this->GetValue('SummaryGroupBy');
                    $until = time();
                    $from = strtotime(date('d.m.Y 00:00:00', $until - (24 * 60 * 60 * $days)));

                    $html = $this->BuildSummary($from, $until, $groupBy, []);
                    $this->SetValue('Summary', $html);
                }
                break;
            default:
                $this->SendDebug(__FUNCTION__, 'invalid ident ' . $ident, 0);
                break;
        }
        if ($r) {
            $this->SetValue($ident, $value);
        }
        if ($queryInterval > 0) {
            $this->MaintainTimer('QueryStatus', $queryInterval);
        }
    }

    private function build_url($url, $params)
    {
        $n = 0;
        if (is_array($params)) {
            foreach ($params as $param => $value) {
                $url .= ($n++ ? '&' : '?') . $param . '=' . rawurlencode(strval($value));
            }
        }
        return $url;
    }

    private function build_header($headerfields)
    {
        $header = [];
        foreach ($headerfields as $key => $value) {
            $header[] = $key . ': ' . $value;
        }
        return $header;
    }

    private function do_HttpRequest($cmd, $params)
    {
        $host = $this->ReadPropertyString('host');
        $use_https = $this->ReadPropertyBoolean('use_https');
        $port = $this->ReadPropertyInteger('port');
        $password = $this->ReadPropertyString('password');

        if ($port == ($use_https ? 443 : 80)) {
            $port = 0;
        }
        $url = ($use_https ? 'https://' : 'http://') . $host . ($port > 0 ? (':' . $port) : '') . '/' . $cmd;
        $url = $this->build_url($url, array_merge(['pw' => md5($password)], $params));

        $headerfields = [
            'Accept' => 'application/json',
        ];
        $header = $this->build_header($headerfields);

        $this->SendDebug(__FUNCTION__, 'url=' . $url, 0);

        $time_start = microtime(true);

        if ($header != '') {
            $this->SendDebug(__FUNCTION__, '    header=' . print_r($header, true), 0);
        }

        $curl_opts = [
            CURLOPT_URL            => $url,
            CURLOPT_HTTPHEADER     => $header,
            CURLOPT_HEADER         => true,
            CURLINFO_HEADER_OUT    => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 30,
        ];

        $ch = curl_init();
        curl_setopt_array($ch, $curl_opts);
        $response = curl_exec($ch);
        $cerrno = curl_errno($ch);
        $cerror = $cerrno ? curl_error($ch) : '';
        $httpcode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curl_info = curl_getinfo($ch);
        curl_close($ch);

        $duration = round(microtime(true) - $time_start, 2);
        $this->SendDebug(__FUNCTION__, ' => errno=' . $cerrno . ', httpcode=' . $httpcode . ', duration=' . $duration . 's', 0);
        $this->SendDebug(__FUNCTION__, ' => response=' . $response, 0);

        $statuscode = 0;
        $err = '';

        if ($cerrno) {
            $statuscode = self::$IS_SERVERERROR;
            $err = 'got curl-errno ' . $cerrno . ' (' . $cerror . ')';
        } else {
            $header_size = $curl_info['header_size'];
            $head = substr($response, 0, $header_size);
            $body = substr($response, $header_size);
            $this->SendDebug(__FUNCTION__, ' => head=' . $head, 0);
            $this->SendDebug(__FUNCTION__, ' => body=' . $body, 0);

            if ($httpcode != 200) {
                if ($httpcode >= 500 && $httpcode <= 599) {
                    $statuscode = self::$IS_SERVERERROR;
                    $err = 'got http-code ' . $httpcode . ' (server error)';
                } else {
                    $statuscode = self::$IS_HTTPERROR;
                    $err = 'got http-code ' . $httpcode;
                }
            }
        }

        if ($statuscode == 0) {
            $jbody = @json_decode($body, true);
            if ($jbody == false) {
                $this->SendDebug(__FUNCTION__, 'json_last_error_msg=' . json_last_error_msg(), 0);
                $statuscode = self::$IS_INVALIDDATA;
                $err = 'invalid/malformed data';
            }
        }

        if ($statuscode == 0) {
            if (isset($jbody['result'])) {
                $e = [
                    1  => 'Success',
                    2  => 'Unauthorized',
                    3  => 'Mismatch',
                    16 => 'Data missing',
                    17 => 'Out of Range',
                    18 => 'Data format error',
                    19 => 'RF code error',
                    32 => 'Page not found',
                    48 => 'Not permitted',
                ];
                $r = $jbody['result'];
                $s = isset($e[$r]) ? $e[$r] : 'unknown result ' . $r;
                switch ($r) {
                    case 1: // Success
                        $this->SendDebug(__FUNCTION__, '    result=' . $s, 0);
                        break;
                    case 2: // Unauthorized
                        $statuscode = self::$IS_UNAUTHORIZED;
                        $err = $s;
                        break;
                    case 32: // Page not found
                    case 48: // Not permitted
                        $statuscode = self::$IS_FORBIDDEN;
                        $err = $s;
                        break;
                    default:
                        $statuscode = self::$IS_INVALIDDATA;
                        $err = $s;
                        break;
                }
            }
        }

        if ($statuscode) {
            $this->SendDebug(__FUNCTION__, '    statuscode=' . $statuscode . ', err=' . $err, 0);
            $this->MaintainStatus($statuscode);
            return false;
        }

        $this->MaintainStatus(IS_ACTIVE);
        return $body;
    }

    public function SetControllerEnabled(bool $enab)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('ControllerEnabled') == false) {
            return false;
        }

        $params = [
            'en' => ($enab ? 1 : 0),
        ];
        $data = $this->do_HttpRequest('cv', $params);
        return $data !== false;
    }

    public function SetWateringLevel(int $level)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('WateringLevel') == false) {
            return false;
        }

        $level_min = 0;
        $level_max = 250;
        if ($level < $level_min) {
            $this->SendDebug(__FUNCTION__, 'level is < ' . $level_min, 0);
            $level = $level_min;
        }
        if ($level > $level_max) {
            $this->SendDebug(__FUNCTION__, 'level is > ' . $level_max, 0);
            $level = $level_max;
        }

        if ($this->WateringLevelChangeable() == false) {
            $this->SendDebug(__FUNCTION__, 'watering level is not changeable in this weather mode', 0);
            return false;
        }

        $params = [
            'wl' => $level,
        ];
        $data = $this->do_HttpRequest('co', $params);
        return $data !== false;
    }

    public function SetRainDelay(int $mode, int $hour)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('RainDelay') == false) {
            return false;
        }

        if ($mode == 0 /* Set */) {
            $rd = $hour;

            $rd_min = 0;
            $rd_max = 32767;
            if ($rd < $rd_min) {
                $this->SendDebug(__FUNCTION__, 'rain delay is < ' . $rd_min, 0);
                $rd = $rd_min;
            }
            if ($rd > $rd_max) {
                $this->SendDebug(__FUNCTION__, 'rain delay is > ' . $rd_max, 0);
                $rd = $rd_max;
            }
        }
        if ($mode == 1 /* Clear */) {
            $rd = 0;
        }

        $params = [
            'rd' => $rd,
        ];
        $data = $this->do_HttpRequest('cv', $params);
        return $data !== false;
    }

    public function StopAllStations()
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('StopAllStations') == false) {
            return false;
        }

        $params = [
            'rsn' => 1,
        ];
        $data = $this->do_HttpRequest('cv', $params);
        return $data !== false;
    }

    public function StationStartManually(int $sid, int $mode, int $sec)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('StationStartManually') == false) {
            return false;
        }

        if ($mode == 0 /* Set */) {
            $t = $sec;

            $t_min = 0;
            $t_max = 64800;
            if ($t < $t_min) {
                $this->SendDebug(__FUNCTION__, 'timer is < ' . $t_min, 0);
                $t = $t_min;
            }
            if ($t > $t_max) {
                $this->SendDebug(__FUNCTION__, 'timer is > ' . $t_max, 0);
                $t = $t_max;
            }
            $params = [
                'sid' => $sid,
                'en'  => 1,
                't'   => $t,
            ];
        }
        if ($mode == 1 /* Clear */) {
            $params = [
                'sid'  => $sid,
                'en'   => 0,
                't'    => 0,
                'ssta' => 1,
            ];
        }

        $data = $this->do_HttpRequest('cm', $params);
        return $data !== false;
    }

    public function PauseQueue(int $mode, int $sec)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('PauseQueue') == false) {
            return false;
        }

        if ($mode == 0 /* Set */) {
            $dur = $sec;

            $dur_min = 0;
            if ($t < $dur_min) {
                $this->SendDebug(__FUNCTION__, 'duration is < ' . $dur_min, 0);
                $dur = $dur_min;
            }
        }
        if ($mode == 1 /* Clear */) {
            $dur = 0;
        }

        $params = [
            'dur' => $dur,
        ];
        $data = $this->do_HttpRequest('pq', $params);
        return $data !== false;
    }

    public function SetStationDisabled(int $sid, bool $dis)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('StationDisabled', $sid) == false) {
            return false;
        }

        $byte = floor($sid / 8);
        $bit = $sid % 8;

        $bval = 0;
        for ($i = 0; $i < 8; $i++) {
            $post = '_' . (($byte * 8) + $i + 1);
            $b = $this->GetValue('StationDisabled' . $post);
            if ($b) {
                $bval = $this->bit_set($bval, $i);
            }
        }
        if ($dis) {
            $bval = $this->bit_set($bval, $bit);
        } else {
            $bval = $this->bit_clear($bval, $bit);
        }

        $params = [
            'd' . $byte => $bval,
        ];
        $data = $this->do_HttpRequest('cs', $params);
        return $data !== false;
    }

    public function SetStationIgnoreRain(int $sid, bool $ign)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('StationIgnoreRain') == false) {
            return false;
        }

        $byte = floor($sid / 8);
        $bit = $sid % 8;

        $bval = 0;
        for ($i = 0; $i < 8; $i++) {
            $post = '_' . (($byte * 8) + $i + 1);
            $b = $this->GetValue('StationIgnoreRain' . $post);
            if ($b) {
                $bval = $this->bit_set($bval, $i);
            }
        }
        if ($ign) {
            $bval = $this->bit_set($bval, $bit);
        } else {
            $bval = $this->bit_clear($bval, $bit);
        }

        $params = [
            'i' . $byte => $bval,
        ];
        $data = $this->do_HttpRequest('cs', $params);
        return $data !== false;
    }

    public function SetStationIgnoreSensor1(int $sid, bool $ign)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('StationIgnoreSensor1') == false) {
            return false;
        }

        $byte = floor($sid / 8);
        $bit = $sid % 8;

        $bval = 0;
        for ($i = 0; $i < 8; $i++) {
            $post = '_' . (($byte * 8) + $i + 1);
            $b = $this->GetValue('StationIgnoreSensor1' . $post);
            if ($b) {
                $bval = $this->bit_set($bval, $i);
            }
        }
        if ($ign) {
            $bval = $this->bit_set($bval, $bit);
        } else {
            $bval = $this->bit_clear($bval, $bit);
        }

        $params = [
            'j' . $byte => $bval,
        ];
        $data = $this->do_HttpRequest('cs', $params);
        return $data !== false;
    }

    public function SetStationIgnoreSensor2(int $sid, bool $ign)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('StationIgnoreSensor2') == false) {
            return false;
        }

        $byte = floor($sid / 8);
        $bit = $sid % 8;

        $bval = 0;
        for ($i = 0; $i < 8; $i++) {
            $post = '_' . (($byte * 8) + $i + 1);
            $b = $this->GetValue('StationIgnoreSensor2' . $post);
            if ($b) {
                $bval = $this->bit_set($bval, $i);
            }
        }
        if ($ign) {
            $bval = $this->bit_set($bval, $bit);
        } else {
            $bval = $this->bit_clear($bval, $bit);
        }

        $params = [
            'k' . $byte => $bval,
        ];
        $data = $this->do_HttpRequest('cs', $params);
        return $data !== false;
    }

    public function SetStationFlowThreshold(int $sid, float $value)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($value < 0) {
            return false;
        }

        /*
           ● a: Durchschnittliche Strömungsmenge in l/min (/100)
           ● f: Strömungsüberwachungs-Grenzmenge in l/min (/100)
         */
        $params = [
            'f' . $sid => floor($value * 100),
        ];
        $data = $this->do_HttpRequest('cs', $params);
        return $data !== false;
    }

    public function SetProgramEnabled(int $pid, bool $enab)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('ProgramEnabled') == false) {
            return false;
        }
        $params = [
            'pid' => $pid,
            'en'  => ($enab ? 1 : 0),
        ];
        $data = $this->do_HttpRequest('cp', $params);
        return $data !== false;
    }

    public function SetProgramWeatherAdjust(int $pid, bool $enab)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('ProgramWeatherAdjust') == false) {
            return false;
        }
        $params = [
            'pid' => $pid,
            'uwt' => ($enab ? 1 : 0),
        ];
        $data = $this->do_HttpRequest('cp', $params);
        return $data !== false;
    }

    public function ProgramStartManually(int $pid, bool $weatherAdjustmnent)
    {
        if ($this->CheckStatus() == self::$STATUS_INVALID) {
            $this->SendDebug(__FUNCTION__, $this->GetStatusText() . ' => skip', 0);
            return false;
        }

        if ($this->Use4Ident('ProgramStartManually') == false) {
            return false;
        }

        $params = [
            'pid' => $pid,
            'uwt' => ($weatherAdjustmnent ? 1 : 0),
        ];
        $data = $this->do_HttpRequest('mp', $params);
        return $data !== false;
    }

    private function AdjustVariablenames()
    {
        $n_changed = 0;

        $chldIDs = IPS_GetChildrenIDs($this->InstanceID);

        $station_list = (array) @json_decode($this->ReadPropertyString('station_list'), true);
        for ($station_n = 0; $station_n < count($station_list); $station_n++) {
            $station_entry = $station_list[$station_n];

            foreach ($chldIDs as $chldID) {
                $obj = IPS_GetObject($chldID);
                if ($obj['ObjectType'] == OBJECTTYPE_VARIABLE) {
                    if (preg_match('#^Station[^_]+_' . ($station_n + 1) . '$#', $obj['ObjectIdent'], $r)) {
                        if (preg_match('#' . self::$STATION_PREFIX . '[0-9]{2}\[[^\]]*\]:[ ]*(.*)$#', $obj['ObjectName'], $r)) {
                            $s = sprintf(self::$STATION_PREFIX . '%02d[%s]: %s', $station_n + 1, $station_entry['name'], $r[1]);
                            if ($obj['ObjectName'] != $s) {
                                IPS_SetName($chldID, $s);
                                $this->SendDebug(__FUNCTION__, 'id=' . $chldID . ', ident=' . $obj['ObjectIdent'] . ': rename from "' . $obj['ObjectName'] . '" to "' . $s . '"', 0);
                                $n_changed++;
                            }
                        }
                    }
                }
            }
        }
        $program_list = (array) @json_decode($this->ReadPropertyString('program_list'), true);
        for ($program_n = 0; $program_n < count($program_list); $program_n++) {
            $program_entry = $program_list[$program_n];

            foreach ($chldIDs as $chldID) {
                $obj = IPS_GetObject($chldID);
                if ($obj['ObjectType'] == OBJECTTYPE_VARIABLE) {
                    if (preg_match('#^Program[^_]+_' . ($program_n + 1) . '$#', $obj['ObjectIdent'], $r)) {
                        if (preg_match('#' . self::$PROGRAM_PREFIX . '[0-9]{2}\[[^\]]*\]:[ ]*(.*)$#', $obj['ObjectName'], $r)) {
                            $s = sprintf(self::$PROGRAM_PREFIX . '%02d[%s]: %s', $program_n + 1, $program_entry['name'], $r[1]);
                            if ($obj['ObjectName'] != $s) {
                                IPS_SetName($chldID, $s);
                                $this->SendDebug(__FUNCTION__, 'id=' . $chldID . ', ident=' . $obj['ObjectIdent'] . ': rename from "' . $obj['ObjectName'] . '" to "' . $s . '"', 0);
                                $n_changed++;
                            }
                        }
                    }
                }
            }
        }
        $this->SendDebug(__FUNCTION__, 'name of ' . $n_changed . ' variables changed', 0);
    }

    private function SetStationSelection(int $value = null)
    {
        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);
        $station_infos = (array) @json_decode($this->ReadAttributeString('station_infos'), true);

        if (is_null($value)) {
            $value = $this->GetValue('StationSelection');
        }
        if ($value == 0) {
            if ($this->Use4Ident('StationState')) {
                $this->SetValue('StationState', self::$STATION_STATE_DISABLED);
            }
            if ($this->Use4Ident('StationDisabled')) {
                $this->SetValue('StationDisabled', false);
                $this->MaintainAction('StationDisabled', false);
            }
            if ($this->Use4Ident('StationIgnoreRain')) {
                $this->SetValue('StationIgnoreRain', false);
                $this->MaintainAction('StationIgnoreRain', false);
            }
            if ($this->Use4Ident('StationIgnoreSensor1')) {
                $this->SetValue('StationIgnoreSensor1', false);
                $this->MaintainAction('StationIgnoreSensor1', false);
            }
            if ($this->Use4Ident('StationIgnoreSensor2')) {
                $this->SetValue('StationIgnoreSensor2', false);
                $this->MaintainAction('StationIgnoreSensor2', false);
            }
            if ($this->Use4Ident('StationTimeLeft')) {
                $this->SetValue('StationTimeLeft', 0);
            }
            if ($this->Use4Ident('StationLastRun')) {
                $this->SetValue('StationLastRun', 0);
            }
            if ($this->Use4Ident('StationLastDuration')) {
                $this->SetValue('StationLastDuration', 0);
            }
            if ($this->Use4Ident('StationNextRun')) {
                $this->SetValue('StationNextRun', 0);
            }
            if ($this->Use4Ident('StationNextDuration')) {
                $this->SetValue('StationNextDuration', 0);
            }
            if ($this->Use4Ident('StationDailyDuration')) {
                $this->SetValue('StationDailyDuration', 0);
            }
            if ($this->Use4Ident('StationFlowAverage')) {
                $this->SetValue('StationFlowAverage', 0);
            }
            if ($this->Use4Ident('StationFlowThreshold')) {
                $this->SetValue('StationFlowThreshold', 0);
                $this->MaintainAction('StationFlowThreshold', false);
            }
            if ($this->Use4Ident('StationWaterUsage')) {
                $this->SetValue('StationWaterUsage', 0);
            }
            if ($this->Use4Ident('StationDailyWaterUsage')) {
                $this->SetValue('StationDailyWaterUsage', 0);
            }
            if ($this->Use4Ident('StationInfo')) {
                $this->SetValue('StationInfo', '');
            }
            if ($this->Use4Ident('StationRunning')) {
                $this->SetValue('StationRunning', '');
            }
            if ($this->Use4Ident('StationLast')) {
                $this->SetValue('StationLast', '');
            }
            if ($this->Use4Ident('StationSummary')) {
                $this->SetValue('StationSummary', '');
            }

            if ($this->Use4Ident('StationStartManually')) {
                $this->MaintainAction('StationStartManually', false);
                $this->MaintainAction('StationStartManuallyHours', false);
                $this->MaintainAction('StationStartManuallyMinutes', false);
                $this->MaintainAction('StationStartManuallySeconds', false);
            }

            return true;
        }

        $sid = $value - 1;

        $station_info = false;
        for ($i = 0; $i < count($station_infos); $i++) {
            if ($station_infos[$i]['sid'] == $sid) {
                $station_info = $station_infos[$i];
                break;
            }
        }
        if ($station_info == false) {
            $this->SendDebug(__FUNCTION__, 'no station_info for sid=' . $sid, 0);
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'sid=' . $sid . ', info=' . print_r($station_info, true), 0);

        if ($this->Enable4Ident('StationStartManually')) {
            $this->MaintainAction('StationStartManually', true);
            $this->MaintainAction('StationStartManuallyHours', true);
            $this->MaintainAction('StationStartManuallyMinutes', true);
            $this->MaintainAction('StationStartManuallySeconds', true);
        }

        $post = '_' . ($sid + 1);

        if ($this->Use4Ident('StationState', $sid)) {
            $this->SetValue('StationState', $this->GetValue('StationState' . $post));
        }
        if ($this->Use4Ident('StationDisabled', $sid)) {
            $this->SetValue('StationDisabled', $station_info['disabled']);
        }
        if ($this->Enable4Ident('StationDisabled', $sid)) {
            $this->MaintainAction('StationDisabled', true);
        }
        if ($this->Use4Ident('StationIgnoreRain', $sid)) {
            $this->SetValue('StationIgnoreRain', $station_info['ignore_rain']);
        }
        if ($this->Enable4Ident('StationIgnoreRain', $sid)) {
            $this->MaintainAction('StationIgnoreRain', true);
        }
        if ($this->Use4Ident('StationIgnoreSensor1')) {
            $this->SetValue('StationIgnoreSensor1', $station_info['ignore_sn1']);
        }
        if ($this->Enable4Ident('StationIgnoreSensor1')) {
            $this->MaintainAction('StationIgnoreSensor1', true);
        }
        if ($this->Use4Ident('StationIgnoreSensor2')) {
            $this->SetValue('StationIgnoreSensor2', $station_info['ignore_sn2']);
        }
        if ($this->Enable4Ident('StationIgnoreSensor2')) {
            $this->MaintainAction('StationIgnoreSensor2', true);
        }
        if ($this->Use4Ident('StationTimeLeft', $sid)) {
            $this->SetValue('StationTimeLeft', $this->GetValue('StationTimeLeft' . $post));
        }
        if ($this->Use4Ident('StationLastRun', $sid)) {
            $this->SetValue('StationLastRun', $this->GetValue('StationLastRun' . $post));
        }
        if ($this->Use4Ident('StationLastDuration', $sid)) {
            $this->SetValue('StationLastDuration', $this->GetValue('StationLastDuration' . $post));
        }
        if ($this->Use4Ident('StationNextRun', $sid)) {
            $this->SetValue('StationNextRun', $this->GetValue('StationNextRun' . $post));
        }
        if ($this->Use4Ident('StationNextDuration', $sid)) {
            $this->SetValue('StationNextDuration', $this->GetValue('StationNextDuration' . $post));
        }
        if ($this->Use4Ident('StationDailyDuration', $sid)) {
            $this->SetValue('StationDailyDuration', $this->GetValue('StationDailyDuration' . $post));
        }
        if ($this->Use4Ident('StationFlowAverage', $sid)) {
            $this->SetValue('StationFlowAverage', $this->GetValue('StationFlowAverage' . $post));
        }
        if ($this->Use4Ident('StationFlowThreshold', $sid)) {
            $this->SetValue('StationFlowThreshold', $station_info['flow_threshold']);
        }
        if ($this->Enable4Ident('StationFlowThreshold', $sid)) {
            $this->MaintainAction('StationFlowThreshold', true);
        }
        if ($this->Use4Ident('StationWaterUsage', $sid)) {
            $this->SetValue('StationWaterUsage', $this->GetValue('StationWaterUsage' . $post));
        }
        if ($this->Use4Ident('StationDailyWaterUsage', $sid)) {
            $this->SetValue('StationDailyWaterUsage', $this->GetValue('StationDailyWaterUsage' . $post));
        }
        if ($this->Use4Ident('StationInfo')) {
            $this->SetValue('StationInfo', $station_info['info']);
        }
        if ($this->Use4Ident('StationRunning')) {
            $this->SetValue('StationRunning', $controller_infos['running_stations']);
        }
        if ($this->Use4Ident('StationLast')) {
            $this->SetValue('StationLast', $controller_infos['last_station']);
        }

        if ($this->Use4Ident('StationSummary')) {
            $until = time();
            $from = strtotime(date('d.m.Y 00:00:00', $until - (24 * 60 * 60 * 3)));

            $html = $this->BuildSummary($from, $until, self::$LOG_GROUPBY_SID, [$sid]);
            $this->SetValue('StationSummary', $html);
        }

        if ($this->Use4Ident('StationStartManually')) {
            $this->SetupStationStartManually();
        }

        return true;
    }

    private function SetProgramSelection(int $value = null)
    {
        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);
        $program_infos = (array) @json_decode($this->ReadAttributeString('program_infos'), true);

        if (is_null($value)) {
            $value = $this->GetValue('ProgramSelection');
        }
        if ($value == 0) {
            if ($this->Use4Ident('ProgramEnabled')) {
                $this->SetValue('ProgramEnabled', false);
            }
            if ($this->Use4Ident('ProgramWeatherAdjust')) {
                $this->SetValue('ProgramWeatherAdjust', false);
            }
            if ($this->Use4Ident('ProgramStartManually')) {
                $this->SetValue('ProgramStartManually', self::$PROGRAM_START_NOP);
            }
            if ($this->Use4Ident('ProgramInfo')) {
                $this->SetValue('ProgramInfo', '');
            }
            if ($this->Use4Ident('ProgramRunning')) {
                $this->SetValue('ProgramRunning', '');
            }
            if ($this->Use4Ident('ProgramLast')) {
                $this->SetValue('ProgramLast', '');
            }

            return true;
        }

        $pid = $value - 1;

        $program_info = false;
        for ($i = 0; $i < count($program_infos); $i++) {
            if ($program_infos[$i]['pid'] == $pid) {
                $program_info = $program_infos[$i];
                break;
            }
        }
        if ($program_info == false) {
            $this->SendDebug(__FUNCTION__, 'no program_info for pid=' . $pid, 0);
            return false;
        }
        $this->SendDebug(__FUNCTION__, 'pid=' . $pid . ', info=' . print_r($program_info, true), 0);

        if ($this->Use4Ident('ProgramEnabled')) {
            $this->SetValue('ProgramEnabled', $program_info['enabled']);
        }
        if ($this->Use4Ident('ProgramWeatherAdjust')) {
            $this->SetValue('ProgramWeatherAdjust', $program_info['weather_adjustment']);
        }
        if ($this->Use4Ident('ProgramStartManually')) {
            $this->SetValue('ProgramStartManually', self::$PROGRAM_START_NOP);
        }
        if ($this->Use4Ident('ProgramInfo')) {
            $this->SetValue('ProgramInfo', $program_info['info']);
        }
        if ($this->Use4Ident('ProgramRunning')) {
            $this->SetValue('ProgramRunning', $controller_infos['running_programs']);
        }
        if ($this->Use4Ident('ProgramLast')) {
            $this->SetValue('ProgramLast', $controller_infos['last_program']);
        }

        return true;
    }

    private function SetupRainDelay()
    {
        $rainDelayUntil = $this->GetValue('RainDelayUntil');
        if ($rainDelayUntil == 0) {
            $this->SetValue('RainDelayAction', 0 /* Set */);
        } else {
            $this->SetValue('RainDelayDays', 0);
            $this->SetValue('RainDelayHours', 0);
            $this->SetValue('RainDelayAction', 1 /* Clear */);
        }
    }

    private function SetupPauseQueue()
    {
        $pauseQueueUntil = $this->GetValue('PauseQueueUntil');
        if ($pauseQueueUntil == 0) {
            $this->SetValue('PauseQueueAction', 0 /* Set */);
        } else {
            $this->SetValue('PauseQueueHours', 0);
            $this->SetValue('PauseQueueMinutes', 0);
            $this->SetValue('PauseQueueSeconds', 0);
            $this->SetValue('PauseQueueAction', 1 /* Clear */);
        }

        $txt = [
            0 => $this->Translate('Set'),
            1 => $this->Translate('Clear'),
        ];

        $value = $this->GetValue('PauseQueueAction');
        $associations = [
            [
                'Value' => $value,
                'Name'  => isset($txt[$value]) ? $txt[$value] : '???',
            ],
        ];

        $this->UpdateVarProfileAssociations($this->VarProf_PauseQueueAction, $associations);
    }

    private function SetupStationStartManually()
    {
        if ($this->Use4Ident('StationSelection') == false) {
            return false;
        }
        $sid = $this->GetValue('StationSelection');
        if ($sid == 0) {
            return false;
        }
        if ($this->Use4Ident('StationTimeLeft', $sid - 1) == false) {
            return false;
        }
        $timeLeft = $this->GetValue('StationTimeLeft_' . $sid);
        if ($timeLeft == 0) {
            $this->SetValue('StationStartManually', 0 /* Set */);
        } else {
            $this->SetValue('StationStartManuallyHours', 0);
            $this->SetValue('StationStartManuallyMinutes', 0);
            $this->SetValue('StationStartManuallySeconds', 0);
            $this->SetValue('StationStartManually', 1 /* Clear */);
        }

        $txt = [
            0 => $this->Translate('Start'),
            1 => $this->Translate('Stop'),
        ];

        $value = $this->GetValue('StationStartManually');
        $associations = [
            [
                'Value' => $value,
                'Name'  => isset($txt[$value]) ? $txt[$value] : '???',
            ],
        ];

        $this->UpdateVarProfileAssociations($this->VarProf_StationStartManually, $associations);
    }

    private function UpdateVarProfileAssociations(string $ident, $associations = null)
    {
        $varProfile = IPS_GetVariableProfile($ident);
        $old_associations = $varProfile['Associations'];
        foreach ($old_associations as $old_a) {
            $fnd = false;
            foreach ($associations as $a) {
                if ($old_a['Value'] == $a['Value']) {
                    $fnd = true;
                    break;
                }
            }
            if ($fnd == false) {
                $associations[] = [
                    'Value' => $old_a['Value'],
                    'Name'  => '',
                ];
            }
        }
        foreach ($associations as $a) {
            IPS_SetVariableProfileAssociation($ident, $a['Value'], $a['Name'], '', -1);
        }
    }

    private function SetupStationSelection()
    {
        $associations = [
            [
                'Value' => 0,
                'Name'  => '-'
            ],
        ];
        $station_infos = (array) @json_decode($this->ReadAttributeString('station_infos'), true);
        foreach ($station_infos as $info) {
            if ($info['use'] == false || $info['master_id'] != 0) {
                continue;
            }
            $associations[] = [
                'Value' => $info['sid'] + 1,
                'Name'  => $info['name'],
            ];
        }
        $this->UpdateVarProfileAssociations($this->VarProf_Stations, $associations);
    }

    private function SetupProgramSelection()
    {
        $associations = [
            [
                'Value' => 0,
                'Name'  => '-'
            ],
        ];
        $program_infos = (array) @json_decode($this->ReadAttributeString('program_infos'), true);
        foreach ($program_infos as $info) {
            if ($info['use'] == false) {
                continue;
            }
            $associations[] = [
                'Value' => $info['pid'] + 1,
                'Name'  => $info['name'],
            ];
        }
        $this->UpdateVarProfileAssociations($this->VarProf_Programs, $associations);
    }

    private function GetAllChildenIDs($objID, &$objIDs)
    {
        $cIDs = IPS_GetChildrenIDs($objID);
        if ($cIDs != []) {
            $objIDs = array_merge($objIDs, $cIDs);
            foreach ($cIDs as $cID) {
                $this->GetAllChildenIDs($cID, $objIDs);
            }
        }
    }

    private function GetAllIdents()
    {
        $objIDs = [];
        $this->GetAllChildenIDs($this->InstanceID, $objIDs);
        $map = [];
        foreach ($objIDs as $objID) {
            $obj = IPS_GetObject($objID);
            $map[$obj['ObjectIdent']] = $objID;
        }
        return $map;
    }

    private function Use4Ident($ident, $sid = null)
    {
        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);

        $remote_extension = (bool) $this->GetArrayElem($controller_infos, 'remote_extension', false);
        $feature = $this->GetArrayElem($controller_infos, 'feature', '');
        $sensor_type1 = $this->GetArrayElem($controller_infos, 'sensor_type.1', self::$SENSOR_TYPE_NONE);
        $sensor_type2 = $this->GetArrayElem($controller_infos, 'sensor_type.2', self::$SENSOR_TYPE_NONE);
        $has_flowmeter = (bool) $this->GetArrayElem($controller_infos, 'has_flowmeter', false);
        $has_watermeter = $this->HasWaterMeter();
        $with_summary = $this->ReadPropertyBoolean('with_summary');

        $is_master = false;
        if (is_null($sid) == false) {
            $station_infos = (array) @json_decode($this->ReadAttributeString('station_infos'), true);
            foreach ($station_infos as $station_info) {
                if ($station_info['sid'] == $sid) {
                    $is_master = (bool) $this->GetArrayElem($station_info, 'is_master', false);
                    break;
                }
            }
        }

        $r = false;

        switch ($ident) {
            case 'ControllerEnabled':
            case 'CurrentDraw':
            case 'DeviceTime':
            case 'LastRebootCause':
            case 'LastRebootTstamp':
            case 'StationDisabled':
            case 'StationInfo':
            case 'StationSelection':
            case 'StationState':
            case 'WifiStrength':
                $r = true;
                break;
            case 'DailyDuration':
            case 'PauseQueue':
            case 'ProgramEnabled':
            case 'ProgramInfo':
            case 'ProgramLast':
            case 'ProgramRunning':
            case 'ProgramSelection':
            case 'ProgramStartManually':
            case 'ProgramWeatherAdjust':
            case 'RainDelay':
            case 'StationIgnoreRain':
            case 'StopAllStations':
            case 'TotalDuration':
            case 'WateringLevel':
            case 'WeatherQueryStatus':
            case 'WeatherQueryTstamp':
                $r = $remote_extension == false;
                break;
            case 'Summary':
            case 'StationSummary':
                $r = $remote_extension == false && $with_summary;
                break;
            case 'DailyWaterUsage':
            case 'TotalWaterUsage':
                $r = $remote_extension == false && ($has_flowmeter || $has_watermeter);
                break;
            case 'WaterFlowrate':
                $r = $remote_extension == false && $has_flowmeter;
                break;
            case 'SensorState_1':
                $r = $remote_extension == false && in_array($sensor_type1, [self::$SENSOR_TYPE_RAIN, self::$SENSOR_TYPE_SOIL]);
                break;
            case 'SensorState_2':
                $r = $remote_extension == false && in_array($sensor_type2, [self::$SENSOR_TYPE_RAIN, self::$SENSOR_TYPE_SOIL]);
                break;
            case 'StationIgnoreSensor1':
                $r = $remote_extension == false && $sensor_type1 != self::$SENSOR_TYPE_NONE;
                break;
            case 'StationIgnoreSensor2':
                $r = $remote_extension == false && $sensor_type2 != self::$SENSOR_TYPE_NONE;
                break;
            case 'StationDailyDuration':
            case 'StationLastDuration':
            case 'StationLastRun':
            case 'StationNextDuration':
            case 'StationNextRun':
            case 'StationRunning':
            case 'StationLast':
            case 'StationStartManually':
            case 'StationTimeLeft':
            case 'StationTotalDuration':
                $r = $remote_extension == false && $is_master == false;
                break;
            case 'StationDailyWaterUsage':
            case 'StationTotalWaterUsage':
            case 'StationWaterUsage':
                $r = $remote_extension == false && ($has_flowmeter || $has_watermeter) && $is_master == false;
                break;
            case 'StationFlowAverage':
            case 'StationFlowThreshold':
                $r = $remote_extension == false && $has_flowmeter && $feature == 'ASB' && $is_master == false;
                break;
            default:
                break;
        }

        switch ($ident) {
            case 'DailyDuration':
                $with_controller_daily_duration = $this->ReadPropertyBoolean('with_controller_daily_duration');
                if ($with_controller_daily_duration == false) {
                    $r = false;
                }
                break;
            case 'DailyWaterUsage':
                $with_controller_daily_usage = $this->ReadPropertyBoolean('with_controller_daily_usage');
                if ($with_controller_daily_usage == false) {
                    $r = false;
                }
                break;
            case 'TotalDuration':
                $with_station_total_duration = $this->ReadPropertyBoolean('with_station_total_duration');
                if ($with_station_total_duration == false) {
                    $r = false;
                }
                break;
            case 'TotalWaterUsage':
                $with_station_total_usage = $this->ReadPropertyBoolean('with_station_total_usage');
                if ($with_station_total_usage == false) {
                    $r = false;
                }
                break;
            case 'StationDailyDuration':
                $with_station_daily_duration = $this->ReadPropertyBoolean('with_station_daily_duration');
                if ($with_station_daily_duration == false) {
                    $r = false;
                }
                break;
            case 'StationDailyWaterUsage':
                $with_station_daily_usage = $this->ReadPropertyBoolean('with_station_daily_usage');
                if ($with_station_daily_usage == false) {
                    $r = false;
                }
                break;
            case 'StationTotalDuration':
                $with_controller_total_duration = $this->ReadPropertyBoolean('with_controller_total_duration');
                if ($with_controller_total_duration == false) {
                    $r = false;
                }
                break;
            case 'StationTotalWaterUsage':
                $with_controller_total_usage = $this->ReadPropertyBoolean('with_controller_total_usage');
                if ($with_controller_total_usage == false) {
                    $r = false;
                }
                break;
            case 'StationLastDuration':
            case 'StationLastRun':
                $with_station_last_run = $this->ReadPropertyBoolean('with_station_last_run');
                if ($with_station_last_run == false) {
                    $r = false;
                }
                break;
            case 'StationNextDuration':
            case 'StationNextRun':
                $with_station_next_run = $this->ReadPropertyBoolean('with_station_next_run');
                if ($with_station_next_run == false) {
                    $r = false;
                }
                break;
            case 'StationWaterUsage':
                $with_station_usage = $this->ReadPropertyBoolean('with_station_usage');
                if ($with_station_usage == false) {
                    $r = false;
                }
                break;
            case 'StationFlowAverage':
            case 'StationFlowThreshold':
                $with_station_flow = $this->ReadPropertyBoolean('with_station_flow');
                if ($with_station_flow == false) {
                    $r = false;
                }
                break;
            default:
                break;
        }

        return $r;
    }

    private function Enable4Ident($ident, $sid = null)
    {
        $with_summary = $this->ReadPropertyBoolean('with_summary');

        $r = false;

        if ($this->Use4Ident($ident, $sid) == false) {
            return $r;
        }

        $controller_infos = (array) @json_decode($this->ReadAttributeString('controller_infos'), true);

        $remote_extension = (bool) $this->GetArrayElem($controller_infos, 'remote_extension', false);

        switch ($ident) {
            case 'ControllerEnabled':
                $r = true;
                break;
            case 'WateringLevel':
                $r = $remote_extension == false && $this->WateringLevelChangeable();
                break;
            case 'PauseQueue':
            case 'ProgramEnabled':
            case 'ProgramSelection':
            case 'ProgramStartManually':
            case 'ProgramWeatherAdjust':
            case 'RainDelay':
            case 'StopAllStations':
            case 'StationDisabled':
            case 'StationFlowThreshold':
            case 'StationIgnoreRain':
            case 'StationIgnoreSensor1':
            case 'StationIgnoreSensor2':
            case 'StationSelection':
            case 'StationStartManually':
                $r = $remote_extension == false;
                break;
            case 'Summary':
            case 'StationSummary':
                $r = $remote_extension == false && $with_summary;
                break;
        }

        return $r;
    }

    private function Notify($message, $severity, $params)
    {
        $notification_scriptID = $this->ReadPropertyInteger('notification_scriptID');
        if (IPS_ScriptExists($notification_scriptID)) {
            $params['instanceID'] = $this->InstanceID;
            $params['message'] = $message;
            $params['severity'] = $severity;
            @$s = IPS_RunScriptWaitEx($notification_scriptID, $params);
            $this->SendDebug(__FUNCTION__, '... IPS_RunScriptWaitEx(' . $notification_scriptID . ', ' . print_r($params, true) . ')=' . $s, 0);
        }
    }

    private function HasWaterMeter()
    {
        $waterMeterID = $this->ReadPropertyInteger('WaterMeterID');

        return IPS_VariableExists($waterMeterID);
    }

    private function GetWaterMeter()
    {
        $waterMeterID = $this->ReadPropertyInteger('WaterMeterID');
        $waterMeterFactor = $this->ReadPropertyFloat('WaterMeterFactor');

        if (IPS_VariableExists($waterMeterID) == false) {
            return false;
        }
        $water_counter = (float) GetValue($waterMeterID) * $waterMeterFactor;
        $this->SendDebug(__FUNCTION__, 'water_counter=' . $water_counter . ' l', 0);
        return $water_counter;
    }

    private function GetControllerLog(int $start, int $end)
    {
        $station_infos = (array) @json_decode($this->ReadAttributeString('station_infos'), true);
        $program_infos = (array) @json_decode($this->ReadAttributeString('program_infos'), true);

        $start = strtotime(date('d.m.Y 00:00:00', time()));
        $end = time();

        $jlog = [];

        foreach (['', 'fl', 'wl'] as $type) {
            $params = [
                'start' => $start,
                'end'   => $end,
            ];
            if ($type != '') {
                $params['type'] = $type;
            }
            $data = $this->do_HttpRequest('jl', $params);
            if ($data == false) {
                $this->SendDebug(__FUNCTION__, 'no data', 0);
                return;
            }
            $jdata = @json_decode($data, true);
            if ($jdata == false) {
                $this->SendDebug(__FUNCTION__, 'malformed data', 0);
                return;
            }
            $this->SendDebug(__FUNCTION__, 'jdata=' . print_r($jdata, true), 0);
            $jlog = array_merge($jlog, $jdata);
        }

        usort($jlog, function ($a, $b)
        {
            if ($a[3] != $b[3]) {
                return ($a[3] < $b[3]) ? -1 : 1;
            }
            return ($a[1] < $b[1]) ? -1 : 1;
        });

        foreach ($jlog as $entry) {
            $pname = '';
            $sname = '';
            $type = '';

            $pid = $entry[0];

            if (is_numeric($entry[1])) {
                $sid = $entry[1];
                for ($i = 0; $i < count($program_infos); $i++) {
                    if (($program_infos[$i]['pid'] + 1) == $pid) {
                        $pname = $program_infos[$i]['name'];
                        break;
                    } elseif ($pid == self::$ADHOC_PROGRAM) {
                        $pname = $this->Translate('Adhoc program');
                        break;
                    } elseif ($pid == self::$MANUAL_STATION_START) {
                        $pname = $this->Translate('Manual station start');
                        break;
                    }
                }
                for ($i = 0; $i < count($station_infos); $i++) {
                    if ($station_infos[$i]['sid'] == $sid) {
                        $sname = $station_infos[$i]['name'];
                    }
                }
            } else {
                $sid = -1;
                $type = $entry[1];
            }

            $end = $this->AdjustTimestamp($entry[3]);

            $s = '  tstamp=' . date('d.m.y H:i:s', $end);
            switch ($type) {
                case '':	// station
                    $dur = $entry[2];
                    $start = $end - $dur;
                    $flow = isset($entry[4]) ? $entry[4] : 0;
                    if ($dur > 0) {
                        $usage = round($flow * (float) ($dur / 60), self::$PRECISION_USAGE);
                    } else {
                        $usage = 0;
                    }
                    $s .= ', pid=' . $pid . '(' . $pname . '), sid=' . $sid . '(' . $sname . '), dur=' . $dur . 's, flow=' . $flow . ' l/min, usage=' . $usage . ' l';
                    break;
                case 'wl':	// waterlevel
                    $level = $entry[2];
                    $s .= ', type=' . $type . ', level=' . $level . '%';
                    break;
                case 'fl':	// flowsense
                    $count = $entry[0];
                    $volume = $this->ConvertPulses2Volume($count);
                    $s .= ', type=' . $type . ', count=' . $count . ', volume=' . $volume . ' l';
                    break;
                case 'sn1':	// sensor 1
                case 'sn2':	// sensor 2
                case 'rd':	// rain delay
                case 'cu':	// current
                default:
                    $val = $entry[2];
                    $s .= ', type=' . $type . ', val=' . $val;
                    break;
            }
            $this->SendDebug(__FUNCTION__, $s, 0);
        }
    }

    private function AddLog4Station($sid, $start, $end, $duration, $flow, $usage)
    {
        $log = [
            'tstamp'   => $start,
            'type'     => 'station',
            'sid'      => $sid,
            'start'    => $start,
            'end'      => $end,
            'duration' => $duration,
            'flow'     => $flow,
            'usage'    => $usage,
        ];
        return $this->AddLog($log);
    }

    private function ReadLogs()
    {
        $s = $this->GetMediaContent('LogData');
        $logs = @json_decode((string) $s, true);
        if (is_array($logs)) {
            usort($logs, function ($a, $b)
            {
                return ($a['tstamp'] < $b['tstamp']) ? -1 : 1;
            });
        } else {
            $logs = [];
        }
        return $logs;
    }

    private function WriteLogs($logs)
    {
        $this->SetMediaContent('LogData', json_encode($logs));
        return true;
    }

    private function AddLog($log)
    {
        $old_logs = $this->ReadLogs();
        $n_old_logs = count($old_logs);

        $log_max_age = $this->ReadPropertyInteger('log_max_age');
        $ref_ts = time() - ($log_max_age * 24 * 60 * 60);

        $new_logs = [];
        $n_add_logs = 0;
        $n_del_logs = 0;
        if ($old_logs != '') {
            foreach ($old_logs as $old_log) {
                if ($old_log['tstamp'] < $ref_ts) {
                    $n_del_logs++;
                    continue;
                }
                $new_logs[] = $old_log;
            }
        }

        $fnd = false;
        foreach ($old_logs as $old_log) {
            if ($old_log['tstamp'] == $log['tstamp']) {
                $fnd = true;
                break;
            }
        }
        if ($fnd == false) {
            $new_logs[] = $log;
            $n_add_logs++;
            $s = ', new log=' . print_r($log, true);
        } else {
            $s = '';
        }

        $this->SendDebug(__FUNCTION__, 'add=' . $n_add_logs . ', old=' . $n_old_logs . ', del=' . $n_del_logs . $s, 0);

        $this->WriteLogs($new_logs);
        return true;
    }

    public function GetLogs(int $from, int $until, int $groupBy, array $sidList)
    {
        $station_infos = (array) @json_decode($this->ReadAttributeString('station_infos'), true);
        $program_infos = (array) @json_decode($this->ReadAttributeString('program_infos'), true);

        $logs = $this->ReadLogs();
        $n_logs = count($logs);
        usort($logs, function ($a, $b)
        {
            return ($a['tstamp'] > $b['tstamp']) ? -1 : 1;
        });

        $new_logs = [];
        foreach ($logs as $log) {
            if ($from != 0 && $log['tstamp'] < $from) {
                continue;
            }
            if ($until != 0 && $log['tstamp'] > $until) {
                continue;
            }
            if ($sidList !== false) {
                if ($log['type'] != 'station') {
                    continue;
                }
                if ($sidList != [] && in_array($log['sid'], $sidList) == false) {
                    continue;
                }
            }
            for ($i = 0; $i < count($station_infos); $i++) {
                if ($station_infos[$i]['sid'] == $log['sid']) {
                    $sname = $station_infos[$i]['name'];
                    break;
                }
            }
            $log['sname'] = $sname;
            $log['flow'] = round($log['flow'], self::$PRECISION_FLOW);
            $log['usage'] = round($log['usage'], self::$PRECISION_USAGE);
            $new_logs[] = $log;
        }

        switch ($groupBy) {
            case self::$LOG_GROUPBY_DATE:
                $days = [];
                foreach ($new_logs as $log) {
                    $day = date('d.m.Y', $log['tstamp']);
                    $ts = strtotime($day);
                    if (isset($days[$ts]) == false) {
                        $days[$ts] = $day;
                    }
                }
                krsort($days, SORT_NUMERIC);

                $grouped_logs = [];
                foreach ($days as $ts => $day) {
                    $grp = [];
                    $usage = 0;
                    foreach ($new_logs as $log) {
                        if (date('d.m.Y', $log['tstamp']) == $day) {
                            $grp[] = $log;
                            $usage += $log['usage'];
                        }
                    }
                    $grouped_logs[] = [
                        'ts'    => $ts,
                        'day'   => $day,
                        'title' => $day,
                        'usage' => round($usage, self::$PRECISION_USAGE),
                        'logs'  => $grp,
                    ];
                }
                break;
            case self::$LOG_GROUPBY_SID:
            case self::$LOG_GROUPBY_SNAME:
                $stations = [];
                foreach ($new_logs as $log) {
                    $sid = $log['sid'];
                    if (isset($stations[$sid]) == false) {
                        $sname = sprintf(self::$STATION_PREFIX . '%02d', ($sid + 1));
                        for ($i = 0; $i < count($station_infos); $i++) {
                            if ($station_infos[$i]['sid'] == $sid) {
                                $sname = $station_infos[$i]['name'];
                            }
                        }
                        $stations[$sid] = $sname;
                    }
                }
                switch ($groupBy) {
                    case self::$LOG_GROUPBY_SID:
                        ksort($stations, SORT_NUMERIC);
                        break;
                    case self::$LOG_GROUPBY_SNAME:
                        asort($stations);
                        break;
                    default:
                        break;
                }

                $grouped_logs = [];
                foreach ($stations as $sid => $sname) {
                    $grp = [];
                    $usage = 0;
                    foreach ($new_logs as $log) {
                        if ($log['sid'] == $sid) {
                            $grp[] = $log;
                            $usage += $log['usage'];
                        }
                    }
                    $grouped_logs[] = [
                        'sid'   => $sid,
                        'sname' => $sname,
                        'title' => $sname,
                        'usage' => round($usage, self::$PRECISION_USAGE),
                        'logs'  => $grp,
                    ];
                }
                break;
            default:
                $usage = 0;
                foreach ($new_logs as $log) {
                    $usage += $log['usage'];
                }
                $grouped_logs = [
                    [
                        'title' => $this->TranslateFormat('{$from} until {$until}', ['{$from}' => date('d.m.Y', $from), '{$until}' => date('d.m.Y', $until)]),
                        'usage' => round($usage, self::$PRECISION_USAGE),
                        'logs'  => $new_logs,
                    ],
                ];
                break;
        }
        return $grouped_logs;
    }

    private function BuildSummary(int $from, int $until, int $groupBy, array $sidList)
    {
        $summary_scriptID = $this->ReadPropertyInteger('summary_scriptID');

        $grouped_logs = $this->GetLogs($from, $until, $groupBy, $sidList);

        if (IPS_ScriptExists($summary_scriptID)) {
            $params = [
                'InstanceID'   => $this->InstanceID,
                'from'         => $from,
                'until'        => $until,
                'group_by'     => $groupBy,
                'grouped_logs' => json_encode($grouped_logs),
            ];
            $html = IPS_RunScriptWaitEx($summary_scriptID, $params);
            return $html;
        }

        $html = '';

        $now = time();
        $b = false;

        $html = '';
        $html .= '<html>' . PHP_EOL;
        $html .= '<body>' . PHP_EOL;
        $html .= '<style>' . PHP_EOL;
        $html .= 'body { margin: 1; padding: 0; font-family: "Open Sans", sans-serif; font-size: 20px; }' . PHP_EOL;
        $html .= 'table { border-collapse: collapse; border: 0px solid; margin: 0.5em;}' . PHP_EOL;
        $html .= 'th, td { padding: 1; }' . PHP_EOL;
        $html .= 'thead, tdata { text-align: left; }' . PHP_EOL;
        $html .= '#spalte_zeitpunkt { width: 150px; }' . PHP_EOL;
        $html .= '#spalte_station { width: 400px; }' . PHP_EOL;
        $html .= '#spalte_time { width: 120px; }' . PHP_EOL;
        $html .= '#spalte_datetime { width: 150px; }' . PHP_EOL;
        $html .= '#spalte_duration { width: 100px; }' . PHP_EOL;
        $html .= '#spalte_flow { width: 150px; }' . PHP_EOL;
        $html .= '#spalte_usage { width: 150px; }' . PHP_EOL;
        $html .= '</style>' . PHP_EOL;

        foreach ($grouped_logs as $grouped_log) {
            switch ($groupBy) {
                case self::$LOG_GROUPBY_DATE:
                    $title = $grouped_log['title'];
                    $usage = $grouped_log['usage'];
                    $logs = $grouped_log['logs'];
                    break;
                case self::$LOG_GROUPBY_SID:
                case self::$LOG_GROUPBY_SNAME:
                    $title = $grouped_log['title'];
                    $usage = $grouped_log['usage'];
                    $logs = $grouped_log['logs'];
                    break;
                default:
                    $title = $grouped_log['title'];
                    $usage = $grouped_log['usage'];
                    $logs = $grouped_log['logs'];
                    break;
            }

            $html .= '<h4>' . $this->TranslateFormat('{$title} - Usage {$usage} l', ['{$title}' => $title, '{$usage}' => $usage]) . '</h4>' . PHP_EOL;

            switch ($groupBy) {
                case self::$LOG_GROUPBY_DATE:
                    $html .= '<table>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_station"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_time"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_duration"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_time"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_flow"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_usage"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup></colgroup>' . PHP_EOL;
                    $html .= '<thead>' . PHP_EOL;
                    $html .= '<tr>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Station') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Start time') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Run time') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('End time') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Flow') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Usage') . '</th>' . PHP_EOL;
                    $html .= '</tr>' . PHP_EOL;
                    $html .= '</thead>' . PHP_EOL;
                    $html .= '<tdata>' . PHP_EOL;
                    break;
                case self::$LOG_GROUPBY_SID:
                case self::$LOG_GROUPBY_SNAME:
                    $html .= '<table>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_datetime"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_duration"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_datetime"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_flow"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_usage"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup></colgroup>' . PHP_EOL;
                    $html .= '<thead>' . PHP_EOL;
                    $html .= '<tr>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Start time') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Run time') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('End time') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Flow') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Usage') . '</th>' . PHP_EOL;
                    $html .= '</tr>' . PHP_EOL;
                    $html .= '</thead>' . PHP_EOL;
                    $html .= '<tdata>' . PHP_EOL;
                    break;
                default:
                    $html .= '<table>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_station"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_datetime"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_duration"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_datetime"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_flow"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup><col id="spalte_usage"></colgroup>' . PHP_EOL;
                    $html .= '<colgroup></colgroup>' . PHP_EOL;
                    $html .= '<thead>' . PHP_EOL;
                    $html .= '<tr>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Station') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Start time') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Run time') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('End time') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Flow') . '</th>' . PHP_EOL;
                    $html .= '<th>' . $this->Translate('Usage') . '</th>' . PHP_EOL;
                    $html .= '</tr>' . PHP_EOL;
                    $html .= '</thead>' . PHP_EOL;
                    $html .= '<tdata>' . PHP_EOL;
                    break;
                    break;
            }

            foreach ($logs as $log) {
                $tstamp = $log['tstamp'];
                $sid = $log['sid'];
                $sname = $log['sname'];
                $start = $log['start'];
                $end = $log['end'];
                $duration = $log['duration'];
                $flow = $log['flow'];
                $usage = $log['usage'];

                switch ($groupBy) {
                    case self::$LOG_GROUPBY_DATE:
                        $html .= '<tr>' . PHP_EOL;
                        $html .= '<td valign=top>' . $sname . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . date('H:i:s', $start) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . $this->seconds2duration($duration) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . date('H:i:s', $end) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . $this->TranslateFormat('{$flow} l/min', ['{$flow}' => $flow]) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . $this->TranslateFormat('{$usage} l', ['{$usage}' => $usage]) . '</td>' . PHP_EOL;
                        $html .= '</tr>' . PHP_EOL;
                        break;
                    case self::$LOG_GROUPBY_SID:
                    case self::$LOG_GROUPBY_SNAME:
                        $html .= '<tr>' . PHP_EOL;
                        $html .= '<td valign=top>' . date('d.m H:i:s', $start) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . $this->seconds2duration($duration) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . date('d.m H:i:s', $end) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . $this->TranslateFormat('{$flow} l/min', ['{$flow}' => $flow]) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . $this->TranslateFormat('{$usage} l', ['{$usage}' => $usage]) . '</td>' . PHP_EOL;
                        $html .= '</tr>' . PHP_EOL;
                        break;
                    default:
                        $html .= '<tr>' . PHP_EOL;
                        $html .= '<td valign=top>' . $sname . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . date('d.m H:i:s', $start) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . $this->seconds2duration($duration) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . date('d.m H:i:s', $end) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . $this->TranslateFormat('{$flow} l/min', ['{$flow}' => $flow]) . '</td>' . PHP_EOL;
                        $html .= '<td valign=top>' . $this->TranslateFormat('{$usage} l', ['{$usage}' => $usage]) . '</td>' . PHP_EOL;
                        $html .= '</tr>' . PHP_EOL;
                        break;
                }
            }
            $html .= '</tdata>' . PHP_EOL;
            $html .= '</table>' . PHP_EOL;
        }

        if (count($grouped_logs) == 0) {
            $html .= '<center>' . $this->Translate('No irrigations') . '</center><br>' . PHP_EOL;
        }

        $html .= '</body>' . PHP_EOL;
        $html .= '</html>' . PHP_EOL;

        return $html;
    }
}
