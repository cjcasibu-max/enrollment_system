<?php

function parseSchedule(string $schedule): array
{
    $schedule = strtoupper(trim($schedule));

    // Day-only format: "MONDAY, WEDNESDAY, FRIDAY" or "MWF"
    if (preg_match('/^[A-Z,\s]+$/', $schedule) && !preg_match('/\d{1,2}:\d{2}/', $schedule)) {
        $dayAliases = ['M' => 'M', 'T' => 'T', 'W' => 'W', 'R' => 'R', 'TH' => 'R', 'F' => 'F', 'S' => 'S', 'U' => 'U'];
        $days = [];
        $dayText = preg_replace('/\s+/', '', $schedule);
        $index = 0;
        while ($index < strlen($dayText)) {
            $token = $dayText[$index];
            if ($token === 'T' && isset($dayText[$index + 1]) && $dayText[$index + 1] === 'H') {
                $token = 'TH';
                $index++;
            }
            if (isset($dayAliases[$token])) {
                $days[] = $dayAliases[$token];
            }
            $index++;
        }
        return ['days' => array_values(array_unique($days)), 'start' => null, 'end' => null];
    }

    if (!preg_match('/^([A-Z]+)\s+(\d{1,2}:\d{2})\s*(AM|PM)?\s*-\s*(\d{1,2}:\d{2})\s*(AM|PM)?$/', $schedule, $matches)) {
        throw new RuntimeException('Schedule must use the format MWF 8:00-9:00AM or a day list like MONDAY, WEDNESDAY, FRIDAY.');
    }

    $dayAliases = ['M' => 'M', 'T' => 'T', 'W' => 'W', 'R' => 'R', 'TH' => 'R', 'F' => 'F', 'S' => 'S', 'U' => 'U'];
    $days = [];
    $dayText = $matches[1];
    for ($index = 0; $index < strlen($dayText); $index++) {
        $token = $dayText[$index];
        if ($token === 'T' && isset($dayText[$index + 1]) && $dayText[$index + 1] === 'H') {
            $token = 'TH';
            $index++;
        }
        if (!isset($dayAliases[$token])) {
            throw new RuntimeException('Schedule contains an unsupported day code.');
        }
        $days[] = $dayAliases[$token];
    }

    $startMeridiem = $matches[3] ?? '';
    $endMeridiem = $matches[5] ?? '';

    if ($startMeridiem === '' && $endMeridiem !== '') {
        $startHour = (int)explode(':', $matches[2])[0];
        $endHour = (int)explode(':', $matches[4])[0];
        if ($endMeridiem === 'PM') {
            if ($startHour === 12) {
                $startMeridiem = 'PM';
            } elseif ($startHour > 12) {
                $startMeridiem = 'PM';
            } elseif ($startHour >= $endHour) {
                $startMeridiem = 'AM';
            } else {
                $startMeridiem = 'PM';
            }
        } else {
            $startMeridiem = 'AM';
        }
    }

    if ($endMeridiem === '' && $startMeridiem !== '') {
        $endMeridiem = $startMeridiem;
    }

    $start = scheduleMinutes($matches[2], $startMeridiem);
    $end = scheduleMinutes($matches[4], $endMeridiem);
    if ($end <= $start) {
        throw new RuntimeException('Schedule end time must be after its start time.');
    }

    return ['days' => array_values(array_unique($days)), 'start' => $start, 'end' => $end];
}

function scheduleMinutes(string $time, string $meridiem): int
{
    [$hour, $minute] = array_map('intval', explode(':', $time));
    if ($hour < 1 || $hour > 12 || $minute < 0 || $minute > 59) {
        throw new RuntimeException('Schedule contains an invalid time.');
    }
    if ($meridiem === 'AM' && $hour === 12) $hour = 0;
    if ($meridiem === 'PM' && $hour !== 12) $hour += 12;
    return ($hour * 60) + $minute;
}

function schedulesOverlap(string $firstSchedule, string $secondSchedule): bool
{
    $first = parseSchedule($firstSchedule);
    $second = parseSchedule($secondSchedule);
    $sharedDays = array_intersect($first['days'], $second['days']);
    if (empty($sharedDays)) {
        return false;
    }
    // If either schedule has no time component, treat same-day as overlap
    if ($first['start'] === null || $first['end'] === null || $second['start'] === null || $second['end'] === null) {
        return true;
    }
    return $first['start'] < $second['end'] && $second['start'] < $first['end'];
}