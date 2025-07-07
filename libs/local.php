<?php

declare(strict_types=1);

trait OpenSprinklerLocalLib
{
    public static $IS_UNAUTHORIZED = IS_EBASE + 10;
    public static $IS_FORBIDDEN = IS_EBASE + 11;
    public static $IS_SERVERERROR = IS_EBASE + 12;
    public static $IS_HTTPERROR = IS_EBASE + 13;
    public static $IS_INVALIDDATA = IS_EBASE + 14;

    private function GetFormStatus()
    {
        $formStatus = $this->GetCommonFormStatus();

        $formStatus[] = ['code' => self::$IS_UNAUTHORIZED, 'icon' => 'error', 'caption' => 'Instance is inactive (unauthorized)'];
        $formStatus[] = ['code' => self::$IS_FORBIDDEN, 'icon' => 'error', 'caption' => 'Instance is inactive (forbidden)'];
        $formStatus[] = ['code' => self::$IS_SERVERERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (server error)'];
        $formStatus[] = ['code' => self::$IS_HTTPERROR, 'icon' => 'error', 'caption' => 'Instance is inactive (http error)'];
        $formStatus[] = ['code' => self::$IS_INVALIDDATA, 'icon' => 'error', 'caption' => 'Instance is inactive (invalid data)'];

        return $formStatus;
    }

    public static $STATUS_INVALID = 0;
    public static $STATUS_VALID = 1;
    public static $STATUS_RETRYABLE = 2;

    private function CheckStatus()
    {
        switch ($this->GetStatus()) {
            case IS_ACTIVE:
                $class = self::$STATUS_VALID;
                break;
            case self::$IS_SERVERERROR:
            case self::$IS_HTTPERROR:
            case self::$IS_INVALIDDATA:
                $class = self::$STATUS_RETRYABLE;
                break;
            default:
                $class = self::$STATUS_INVALID;
                break;
        }

        return $class;
    }

    public static $WEATHER_STATUS_OK = 0;
    public static $WEATHER_STATUS_REQUEST_NOT_RECEIVED = -1;
    public static $WEATHER_STATUS_CANNOT_CONNECT = -2;
    public static $WEATHER_STATUS_REQUEST_TIMEOUT = -3;
    public static $WEATHER_STATUS_EMPTY = -4;
    public static $WEATHER_STATUS_BAD_DATA = 1;
    public static $WEATHER_STATUS_INSUFFICIENT_DATA = 10;
    public static $WEATHER_STATUS_MISSING_FIELD = 11;
    public static $WEATHER_STATUS_API_ERROR = 12;
    public static $WEATHER_STATUS_LOCATION_ERROR = 2;
    public static $WEATHER_STATUS_LOCATION_SERVICE_API_ERROR = 20;
    public static $WEATHER_STATUS_NO_LOCATION_FOUND = 21;
    public static $WEATHER_STATUS_INVALID_LOCATON_FORMAT = 22;
    public static $WEATHER_STATUS_PWS_ERROR = 3;
    public static $WEATHER_STATUS_INVALID_PWS_ID = 30;
    public static $WEATHER_STATUS_INVALID_PWS_API_KEY = 31;
    public static $WEATHER_STATUS_PWS_AUTH_ERROR = 32;
    public static $WEATHER_STATUS_PWS_NOT_SUPPORTED = 33;
    public static $WEATHER_STATUS_PWS_NOT_PROVIDED = 34;
    public static $WEATHER_STATUS_ADJUSTMENT_METHOD_ERROR = 4;
    public static $WEATHER_STATUS_ADJUSTMENT_METHOD_UNSUPPORTED = 40;
    public static $WEATHER_STATUS_ADJUSTMENT_METHOD_INVALID = 41;
    public static $WEATHER_STATUS_ADJUSTMENT_OPTION_ERROR = 5;
    public static $WEATHER_STATUS_ADJUSTMENT_OPTION_MALFORMED = 50;
    public static $WEATHER_STATUS_ADJUSTMENT_OPTION_MISSING = 51;
    public static $WEATHER_STATUS_UNEXPECTED_ERROR = 99;

    public static $REBOOT_CAUSE_NONE = 0;
    public static $REBOOT_CAUSE_RESET = 1;
    public static $REBOOT_CAUSE_BUTTON = 2;
    public static $REBOOT_CAUSE_RSTAP = 3;
    public static $REBOOT_CAUSE_TIMER = 4;
    public static $REBOOT_CAUSE_WEB = 5;
    public static $REBOOT_CAUSE_WIFIDONE = 6;
    public static $REBOOT_CAUSE_FWUPDATE = 7;
    public static $REBOOT_CAUSE_WEATHER_FAIL = 8;
    public static $REBOOT_CAUSE_NETWORK_FAIL = 9;
    public static $REBOOT_CAUSE_NTP = 10;
    public static $REBOOT_CAUSE_PROGRAM = 11;
    public static $REBOOT_CAUSE_POWERON = 99;

    public static $NUM_SEQUENTIAL_GROUPS = 4;
    public static $PARALLEL_GROUP = 255;

    public static $WEATHER_METHOD_MANUAL = 0;
    public static $WEATHER_METHOD_ZIMMERMAN = 1;
    public static $WEATHER_METHOD_AUTORAINDELY = 2;
    public static $WEATHER_METHOD_ETO = 3;
    public static $WEATHER_METHOD_MONTHLY = 4;

    public static $SENSOR_TYPE_NONE = 0;
    public static $SENSOR_TYPE_RAIN = 1;
    public static $SENSOR_TYPE_FLOW = 2;
    public static $SENSOR_TYPE_SOIL = 3;
    public static $SENSOR_TYPE_PROGRAM_SWITCH = 240;

    public static $SENSOR_OPTION_NORMALLY_CLOSE = 0;
    public static $SENSOR_OPTION_NORMALLY_OPEN = 1;

    public static $STATION_STATE_DISABLED = 0;
    public static $STATION_STATE_READY = 1;
    public static $STATION_STATE_QUEUED = 2;
    public static $STATION_STATE_WATERING = 3;
    public static $STATION_STATE_CLOSED = 10;
    public static $STATION_STATE_OPENED = 11;

    public static $PROGRAM_STATE_DISABLED = 0;
    public static $PROGRAM_STATE_READY = 1;
    public static $PROGRAM_STATE_QUEUED = 2;
    public static $PROGRAM_STATE_RUNNING = 3;

    public static $PROGRAM_START_NOP = 0;
    public static $PROGRAM_START_WITHOUT_WEATHER = 1;
    public static $PROGRAM_START_WITH_WEATHER = 2;

    public static $PROGRAM_DAY_RESTRICTION_NONE = 0;
    public static $PROGRAM_DAY_RESTRICTION_ODD = 1;
    public static $PROGRAM_DAY_RESTRICTION_EVEN = 2;

    public static $PROGRAM_SCHEDULE_TYPE_WEEKDAY = 0;
    public static $PROGRAM_SCHEDULE_TYPE_INTERVAL = 1;

    public static $PROGRAM_STARTTIME_TYPE_REPEATING = 0;
    public static $PROGRAM_STARTTIME_TYPE_FIXED = 1;

    public static $LOG_GROUPBY_NONE = 0;
    public static $LOG_GROUPBY_DATE = 1;
    public static $LOG_GROUPBY_SID = 2;
    public static $LOG_GROUPBY_SNAME = 3;

    private function InstallVarProfiles(bool $reInstall = false)
    {
        if ($reInstall) {
            $this->SendDebug(__FUNCTION__, 'reInstall=' . $this->bool2str($reInstall), 0);
        }

        $associations = [
            ['Wert' => false, 'Name' => $this->Translate('no'), 'Farbe' => -1],
            ['Wert' => true, 'Name' => $this->Translate('yes'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.YesNo', VARIABLETYPE_BOOLEAN, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => false, 'Name' => '-', 'Farbe' => -1],
            ['Wert' => true, 'Name' => $this->Translate('triggered'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.SensorState', VARIABLETYPE_BOOLEAN, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$STATION_STATE_DISABLED, 'Name' => $this->Translate('disabled'), 'Farbe' => -1],
            ['Wert' => self::$STATION_STATE_READY, 'Name' => $this->Translate('ready'), 'Farbe' => -1],
            ['Wert' => self::$STATION_STATE_QUEUED, 'Name' => $this->Translate('queued'), 'Farbe' => -1],
            ['Wert' => self::$STATION_STATE_WATERING, 'Name' => $this->Translate('watering'), 'Farbe' => -1],
            ['Wert' => self::$STATION_STATE_CLOSED, 'Name' => $this->Translate('closed'), 'Farbe' => -1],
            ['Wert' => self::$STATION_STATE_OPENED, 'Name' => $this->Translate('opened'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.StationState', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$PROGRAM_START_NOP, 'Name' => '-', 'Farbe' => -1],
            ['Wert' => self::$PROGRAM_START_WITHOUT_WEATHER, 'Name' => $this->Translate('without weather adjustment'), 'Farbe' => -1],
            ['Wert' => self::$PROGRAM_START_WITH_WEATHER, 'Name' => $this->Translate('with weather adjustment'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.ProgramStart', VARIABLETYPE_INTEGER, '', 0, 0, 0, 0, '', $associations, $reInstall);

        $this->CreateVarProfile('OpenSprinkler.Duration', VARIABLETYPE_INTEGER, ' s', 0, 0, 0, 0, 'Hourglass', [], $reInstall);

        $associations = [
            ['Wert' => 1, 'Name' => '%d%%', 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.WateringLevel', VARIABLETYPE_INTEGER, '', 0, 250, 0, 1, '', $associations, $reInstall);

        $this->CreateVarProfile('OpenSprinkler.RainDelayDays', VARIABLETYPE_INTEGER, ' d', 0, 99, 1, 0, 'Hourglass', [], $reInstall);
        $this->CreateVarProfile('OpenSprinkler.RainDelayHours', VARIABLETYPE_INTEGER, ' h', 0, 23, 1, 0, 'Hourglass', [], $reInstall);
        $associations = [
            ['Wert' => 0, 'Name' => $this->Translate('Set'), 'Farbe' => -1],
            ['Wert' => 1, 'Name' => $this->Translate('Clear'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.RainDelayAction', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => 0, 'Name' => $this->Translate('Execute'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.StopAllStations', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $this->CreateVarProfile('OpenSprinkler.PauseQueueHours', VARIABLETYPE_INTEGER, ' h', 0, 99, 1, 0, 'Hourglass', [], $reInstall);
        $this->CreateVarProfile('OpenSprinkler.PauseQueueMinutes', VARIABLETYPE_INTEGER, ' m', 0, 59, 1, 0, 'Hourglass', [], $reInstall);
        $this->CreateVarProfile('OpenSprinkler.PauseQueueSeconds', VARIABLETYPE_INTEGER, ' s', 0, 59, 1, 0, 'Hourglass', [], $reInstall);

        $this->CreateVarProfile('OpenSprinkler.StationStartManuallyHours', VARIABLETYPE_INTEGER, ' h', 0, 18, 1, 0, 'Hourglass', [], $reInstall);
        $this->CreateVarProfile('OpenSprinkler.StationStartManuallyMinutes', VARIABLETYPE_INTEGER, ' m', 0, 59, 1, 0, 'Hourglass', [], $reInstall);
        $this->CreateVarProfile('OpenSprinkler.StationStartManuallySeconds', VARIABLETYPE_INTEGER, ' s', 0, 59, 1, 0, 'Hourglass', [], $reInstall);

        $this->CreateVarProfile('OpenSprinkler.Wifi', VARIABLETYPE_INTEGER, ' dBm', 0, 0, 0, 0, 'Intensity', [], $reInstall);

        $associations = [
            ['Wert' => self::$WEATHER_STATUS_OK, 'Name' => $this->Translate('Ok'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_REQUEST_NOT_RECEIVED, 'Name' => $this->Translate('Request not received'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_CANNOT_CONNECT, 'Name' => $this->Translate('Cannot connect to weather server'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_REQUEST_TIMEOUT, 'Name' => $this->Translate('Request time out'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_EMPTY, 'Name' => $this->Translate('Received empty return'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_BAD_DATA, 'Name' => $this->Translate('Problem with the weather information'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_INSUFFICIENT_DATA, 'Name' => $this->Translate('No 24 hour period available'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_MISSING_FIELD, 'Name' => $this->Translate('Missing field in weather data'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_API_ERROR, 'Name' => $this->Translate('API error'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_LOCATION_ERROR, 'Name' => $this->Translate('Location not resolved'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_LOCATION_SERVICE_API_ERROR, 'Name' => $this->Translate('Error resolvind location'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_NO_LOCATION_FOUND, 'Name' => $this->Translate('Location not found'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_INVALID_LOCATON_FORMAT, 'Name' => $this->Translate('Location format error'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_PWS_ERROR, 'Name' => $this->Translate('PWS error'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_INVALID_PWS_ID, 'Name' => $this->Translate('Invalid PWS ID'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_INVALID_PWS_API_KEY, 'Name' => $this->Translate('Invalid PWS API key'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_PWS_AUTH_ERROR, 'Name' => $this->Translate('PWS authentification error'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_PWS_NOT_SUPPORTED, 'Name' => $this->Translate('PWS data missing'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_PWS_NOT_PROVIDED, 'Name' => $this->Translate('PWS mot provided'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_ADJUSTMENT_METHOD_ERROR, 'Name' => $this->Translate('Error in adjustment or watering restrictions'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_ADJUSTMENT_METHOD_UNSUPPORTED, 'Name' => $this->Translate('Adjustment incompatible to weather service'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_ADJUSTMENT_METHOD_INVALID, 'Name' => $this->Translate('Invalid adjustment method'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_ADJUSTMENT_OPTION_ERROR, 'Name' => $this->Translate('Error in adjustment options'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_ADJUSTMENT_OPTION_MALFORMED, 'Name' => $this->Translate('Malformed adjustment option'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_ADJUSTMENT_OPTION_MISSING, 'Name' => $this->Translate('Adjustment option missing'), 'Farbe' => -1],
            ['Wert' => self::$WEATHER_STATUS_UNEXPECTED_ERROR, 'Name' => $this->Translate('Unexpected error'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.WeatherQueryStatus', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$REBOOT_CAUSE_NONE, 'Name' => $this->Translate('None'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_RESET, 'Name' => $this->Translate('Factory reset'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_BUTTON, 'Name' => $this->Translate('Triggered by buttons'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_RSTAP, 'Name' => $this->Translate('Reset to AP mode'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_TIMER, 'Name' => $this->Translate('Timer triggered reboot'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_WEB, 'Name' => $this->Translate('API triggered reboot'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_WIFIDONE, 'Name' => $this->Translate('Switch from AP to client mode'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_FWUPDATE, 'Name' => $this->Translate('Firmware update'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_WEATHER_FAIL, 'Name' => $this->Translate('Weather call failed for more than 24 hours'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_NETWORK_FAIL, 'Name' => $this->Translate('Network failed for too many times'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_NTP, 'Name' => $this->Translate('Reboot due to first-time NTP sync'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_PROGRAM, 'Name' => $this->Translate('Triggered by program'), 'Farbe' => -1],
            ['Wert' => self::$REBOOT_CAUSE_POWERON, 'Name' => $this->Translate('Power on'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.RebootCause', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $this->CreateVarProfile('OpenSprinkler.Current', VARIABLETYPE_INTEGER, ' mA', 0, 0, 0, 0, '', [], $reInstall);

        $associations = [
            ['Wert' => self::$PROGRAM_DAY_RESTRICTION_NONE, 'Name' => $this->Translate('none'), 'Farbe' => -1],
            ['Wert' => self::$PROGRAM_DAY_RESTRICTION_ODD, 'Name' => $this->Translate('odd'), 'Farbe' => -1],
            ['Wert' => self::$PROGRAM_DAY_RESTRICTION_EVEN, 'Name' => $this->Translate('even'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.ProgramDayRestriction', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$PROGRAM_SCHEDULE_TYPE_WEEKDAY, 'Name' => $this->Translate('weekday'), 'Farbe' => -1],
            ['Wert' => self::$PROGRAM_SCHEDULE_TYPE_INTERVAL, 'Name' => $this->Translate('Interval'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.ProgramScheduleType', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$PROGRAM_STARTTIME_TYPE_REPEATING, 'Name' => $this->Translate('repeating'), 'Farbe' => -1],
            ['Wert' => self::$PROGRAM_STARTTIME_TYPE_FIXED, 'Name' => $this->Translate('fixed'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.ProgramStarttimeType', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);

        $associations = [
            ['Wert' => self::$LOG_GROUPBY_NONE, 'Name' => $this->Translate('None'), 'Farbe' => -1],
            ['Wert' => self::$LOG_GROUPBY_DATE, 'Name' => $this->Translate('Date'), 'Farbe' => -1],
            ['Wert' => self::$LOG_GROUPBY_SID, 'Name' => $this->Translate('Station ID'), 'Farbe' => -1],
            ['Wert' => self::$LOG_GROUPBY_SNAME, 'Name' => $this->Translate('Station name'), 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.SummaryGroupBy', VARIABLETYPE_INTEGER, '', 0, 0, 0, 1, '', $associations, $reInstall);
        $associations = [
            ['Wert' => 0, 'Name' => $this->Translate('today only'), 'Farbe' => -1],
            ['Wert' => 1, 'Name' => '%d', 'Farbe' => -1],
        ];
        $this->CreateVarProfile('OpenSprinkler.SummaryDays', VARIABLETYPE_INTEGER, '', 0, 90, 1, 0, 'Hourglass', $associations, $reInstall);

        $this->CreateVarProfile('OpenSprinkler.IrrigationDurationHours', VARIABLETYPE_INTEGER, ' h', 0, 18, 1, 0, 'Hourglass', [], $reInstall);
        $this->CreateVarProfile('OpenSprinkler.IrrigationDurationMinutes', VARIABLETYPE_INTEGER, ' m', 0, 59, 1, 0, 'Hourglass', [], $reInstall);
        $this->CreateVarProfile('OpenSprinkler.IrrigationDurationSeconds', VARIABLETYPE_INTEGER, ' s', 0, 59, 1, 0, 'Hourglass', [], $reInstall);

        $this->CreateVarProfile('OpenSprinkler.WaterFlowrate', VARIABLETYPE_FLOAT, ' l/min', 0, 100, 0.1, 2, '', [], $reInstall);
        $this->CreateVarProfile('OpenSprinkler.WaterFlowmeter', VARIABLETYPE_FLOAT, ' l', 0, 0, 0, 1, 'Gauge', [], $reInstall);
    }

    private function Group2String($grp)
    {
        if ($grp == 0 && $grp < self::$NUM_SEQUENTIAL_GROUPS) {
            $ret = chr(ord('A') + $grp);
        } elseif ($grp == self::$PARALLEL_GROUP) {
            $ret = 'P';
        } else {
            $ret = '?';
        }
        return $ret;
    }

    private function SensorTypeMapping()
    {
        return [
            self::$SENSOR_TYPE_NONE           => 'None',
            self::$SENSOR_TYPE_RAIN           => 'Rain sensor',
            self::$SENSOR_TYPE_FLOW           => 'Flow sensor',
            self::$SENSOR_TYPE_SOIL           => 'Soil sensor',
            self::$SENSOR_TYPE_PROGRAM_SWITCH => 'Program switch',
        ];
    }

    private function SensorType2String($sensorType)
    {
        $sensorTypeMap = $this->SensorTypeMapping();
        if (isset($sensorTypeMap[$sensorType])) {
            $s = $this->Translate($sensorTypeMap[$sensorType]);
        } else {
            $s = $this->Translate('Unknown sensor type') . ' ' . $sensorType;
        }
        return $s;
    }

    private function SensorOptionMapping()
    {
        return [
            self::$SENSOR_OPTION_NORMALLY_OPEN  => 'normally open',
            self::$SENSOR_OPTION_NORMALLY_CLOSE => 'normally closed',
        ];
    }

    private function SensorOption2String($sensorOption)
    {
        $sensorOptionMap = $this->SensorOptionMapping();
        if (isset($sensorOptionMap[$sensorOption])) {
            $s = $this->Translate($sensorOptionMap[$sensorOption]);
        } else {
            $s = $this->Translate('Unknown sensor option') . ' ' . $sensorOption;
        }
        return $s;
    }

    private function WeatherMethodMapping()
    {
        return [
            self::$WEATHER_METHOD_MANUAL       => 'Manual operation',
            self::$WEATHER_METHOD_ZIMMERMAN    => 'Zimmermann',
            self::$WEATHER_METHOD_AUTORAINDELY => 'Auto rain delay',
            self::$WEATHER_METHOD_ETO          => 'ETo',
            self::$WEATHER_METHOD_MONTHLY      => 'Monthly',
        ];
    }

    private function WeatherMethod2String($weatherMethod)
    {
        $weatherMethodMap = $this->WeatherMethodMapping();
        if (isset($weatherMethodMap[$weatherMethod])) {
            $s = $this->Translate($weatherMethodMap[$weatherMethod]);
        } else {
            $s = $this->Translate('Unknown weather method') . ' ' . $weatherMethod;
        }
        return $s;
    }

    private function ProgramDayRestrictionMapping()
    {
        return [
            self::$PROGRAM_DAY_RESTRICTION_NONE => 'none',
            self::$PROGRAM_DAY_RESTRICTION_ODD  => 'odd',
            self::$PROGRAM_DAY_RESTRICTION_EVEN => 'even',
        ];
    }

    private function ProgramDayRestriction2String($programDayRestriction)
    {
        $programDayRestrictionMap = $this->ProgramDayRestrictionMapping();
        if (isset($programDayRestrictionMap[$programDayRestriction])) {
            $s = $this->Translate($programDayRestrictionMap[$programDayRestriction]);
        } else {
            $s = $this->Translate('Unknown program day restriction') . ' ' . $programDayRestriction;
        }
        return $s;
    }

    private function ProgramDayAsOptions()
    {
        $maps = $this->ProgramDayMapping();
        $opts = [];
        foreach ($maps as $u => $e) {
            $opts[] = [
                'caption' => $e,
                'value'   => $u,
            ];
        }
        return $opts;
    }

    private function ProgramScheduleTypeMapping()
    {
        return [
            self::$PROGRAM_SCHEDULE_TYPE_WEEKDAY  => 'weekday',
            self::$PROGRAM_SCHEDULE_TYPE_INTERVAL => 'interval',
        ];
    }

    private function ProgramScheduleType2String($programScheduleType)
    {
        $programScheduleTypeMap = $this->ProgramScheduleTypeMapping();
        if (isset($programScheduleTypeMap[$programScheduleType])) {
            $s = $this->Translate($programScheduleTypeMap[$programScheduleType]);
        } else {
            $s = $this->Translate('Unknown program schedule type') . ' ' . $programScheduleType;
        }
        return $s;
    }

    private function ProgramScheduleTypeAsOptions()
    {
        $maps = $this->ProgramScheduleTypeMapping();
        $opts = [];
        foreach ($maps as $u => $e) {
            $opts[] = [
                'caption' => $e,
                'value'   => $u,
            ];
        }
        return $opts;
    }

    private function ProgramStarttimeTypeMapping()
    {
        return [
            self::$PROGRAM_STARTTIME_TYPE_REPEATING => 'repeating',
            self::$PROGRAM_STARTTIME_TYPE_FIXED     => 'fixed',
        ];
    }

    private function ProgramStarttimeType2String($programStarttimeType)
    {
        $programStarttimeTypeMap = $this->ProgramStarttimeTypeMapping();
        if (isset($programStarttimeTypeMap[$programStarttimeType])) {
            $s = $this->Translate($programStarttimeTypeMap[$programStarttimeType]);
        } else {
            $s = $this->Translate('Unknown program starttime type') . ' ' . $programStarttimeType;
        }
        return $s;
    }

    private function ProgramStarttimeTypeAsOptions()
    {
        $maps = $this->ProgramStarttimeTypeMapping();
        $opts = [];
        foreach ($maps as $u => $e) {
            $opts[] = [
                'caption' => $e,
                'value'   => $u,
            ];
        }
        return $opts;
    }

    private function LogGroupByMapping()
    {
        return [
            self::$LOG_GROUPBY_NONE    => 'None',
            self::$LOG_GROUPBY_DATE    => 'Date',
            self::$LOG_GROUPBY_SID     => 'Station ID',
            self::$LOG_GROUPBY_SNAME   => 'Station name',
        ];
    }

    private function LogGroupByAsOptions()
    {
        $maps = $this->LogGroupByMapping();
        $opts = [];
        foreach ($maps as $u => $e) {
            $opts[] = [
                'caption' => $e,
                'value'   => $u,
            ];
        }
        return $opts;
    }
}
