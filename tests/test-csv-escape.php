<?php
// Test script for CSV formula injection escaping

// Mock minimal environment
if (!defined('ABSPATH')) {
    define('ABSPATH', __DIR__ . '/../');
}

require_once __DIR__ . '/../includes/db-helpers.php';

$test_cases = array(
    // [input, expected_output, description]
    array('normal text', 'normal text', 'Normal text remains unchanged'),
    array('12345', '12345', 'Normal numeric text remains unchanged'),
    array('=SUM(1+1)', "'=SUM(1+1)", 'Equal sign is escaped with leading single quote'),
    array('+12345', "'+12345", 'Plus sign is escaped'),
    array('-5+5', "'-5+5", 'Minus sign is escaped'),
    array('@SUM(1+1)', "'@SUM(1+1)", 'At sign is escaped'),
    array("\tcmd", "'\tcmd", 'Tab character is escaped'),
    array("\rcmd", "'\rcmd", 'Carriage return is escaped'),
    array('|cmd', "'|cmd", 'Pipe character is escaped'),
    array('', '', 'Empty string remains empty string'),
    array(null, '', 'Null is converted to empty string'),
);

$passed = 0;
$failed = 0;

foreach ($test_cases as $index => $case) {
    list($input, $expected, $desc) = $case;
    if (!function_exists('wppc_escape_csv_cell')) {
        echo "FAIL: Function wppc_escape_csv_cell does not exist!\n";
        exit(1);
    }
    $actual = wppc_escape_csv_cell($input);
    if ($actual === $expected) {
        $passed++;
        echo "PASS: Case {$index} - {$desc}\n";
    } else {
        $failed++;
        echo "FAIL: Case {$index} - {$desc} (Expected: " . var_export($expected, true) . ", Got: " . var_export($actual, true) . ")\n";
    }
}

echo "\nSummary: {$passed} passed, {$failed} failed.\n";
if ($failed > 0) {
    exit(1);
}
exit(0);
