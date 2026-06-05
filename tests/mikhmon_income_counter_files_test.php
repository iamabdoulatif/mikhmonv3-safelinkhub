<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';

class IncomeCounterFileApiStub
{
    public $queries = array();

    public function comm($path, $params = array())
    {
        $this->queries[] = array($path, $params);
        $values = array(
            'mikhmon-income-day-jun2026-05-count.txt' => '3',
            'mikhmon-income-day-jun2026-05-total.txt' => '4500',
            'mikhmon-income-month-jun2026-count.txt' => '12',
            'mikhmon-income-month-jun2026-total.txt' => '18000',
            'mikhmon-income-global-count.txt' => '99',
            'mikhmon-income-global-total.txt' => '99000',
        );
        $name = isset($params['?name']) ? $params['?name'] : '';
        return isset($values[$name]) ? array(array('name' => $name, 'contents' => $values[$name])) : array();
    }
}

$api = new IncomeCounterFileApiStub();
$summary = mikhmon_income_summary_from_counter_files($api, 'jun/05/2026');

$expected = array(
    'today_count' => 3,
    'today_total' => 4500.0,
    'month_count' => 12,
    'month_total' => 18000.0,
);
foreach ($expected as $key => $value) {
    if ($summary[$key] !== $value) {
        fwrite(STDERR, "{$key} expected {$value}, got {$summary[$key]}\n");
        exit(1);
    }
}

$scheduler = mikhmon_income_counter_scheduler_source();
foreach (array('mikhmon-income-day-', 'mikhmon-income-month-', 'mikhmon-income-global', '/file add', '/file set', '/system script find where comment=mikhmon') as $needle) {
    if (strpos($scheduler, $needle) === false) {
        fwrite(STDERR, "Scheduler script missing {$needle}\n");
        exit(1);
    }
}

echo "OK\n";
