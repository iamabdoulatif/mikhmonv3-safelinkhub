<?php

require_once __DIR__ . '/../include/mikhmon_compat.php';

class ReportLatestSalesMonthApiStub
{
    public $timeout = 3;
    public $queries = array();
    private $sales;

    public function __construct($sales)
    {
        $this->sales = $sales;
    }

    public function comm($path, $params = array())
    {
        $this->queries[] = array($path, $params);
        if ($path === '/system/script/print') {
            return $this->sales;
        }

        return array();
    }
}

class ReportPartialFilteredSalesApiStub extends ReportLatestSalesMonthApiStub
{
    private $filteredSales;
    private $allSales;

    public function __construct($filteredSales, $allSales)
    {
        parent::__construct($allSales);
        $this->filteredSales = $filteredSales;
        $this->allSales = $allSales;
    }

    public function comm($path, $params = array())
    {
        $this->queries[] = array($path, $params);
        if ($path === '/system/script/print' && isset($params['?comment'])) {
            return $this->filteredSales;
        }
        if ($path === '/system/script/print') {
            return $this->allSales;
        }

        return array();
    }
}

$api = new ReportLatestSalesMonthApiStub(array(
    array(
        'name' => 'aug/01/2024-|-09:00:00-|-august-|-500-|-10.0.0.1-|-CC-|-5d-|-05-JOURS-|-vc-aug',
        'source' => 'aug/01/2024',
        'owner' => 'aug2024',
        'comment' => 'mikhmon',
    ),
    array(
        'name' => 'nov/30/2024-|-10:00:00-|-old-|-500-|-10.0.0.2-|-AA-|-5d-|-05-JOURS-|-vc-old',
        'source' => 'nov/30/2024',
        'owner' => 'nov2024',
        'comment' => 'mikhmon',
    ),
    array(
        'name' => 'dec/14/2024-|-11:00:00-|-latest-|-1000-|-10.0.0.3-|-BB-|-1w-|-01-SEMAINE-|-vc-latest',
        'source' => 'dec/14/2024',
        'owner' => 'dec2024',
        'comment' => 'mikhmon',
    ),
));

$report = mikhmon_report_sales_for_month($api, 'jun2026', 'jun/13/2026');
if ($report['month_key'] !== 'dec2024' || count($report['rows']) !== 1) {
    fwrite(STDERR, 'empty report months must fall back to the latest month containing sales' . PHP_EOL);
    exit(1);
}

$sale = mikhmon_parse_sale_script($report['rows'][0]);
if ($sale['user'] !== 'latest') {
    fwrite(STDERR, 'report fallback must return rows from the latest sales month' . PHP_EOL);
    exit(1);
}

$partialApi = new ReportPartialFilteredSalesApiStub(
    array(
        array(
            'name' => 'may/01/2024-|-09:00:00-|-partial-|-500-|-10.0.0.1-|-CC-|-5d-|-05-JOURS-|-vc-partial',
            'source' => 'may/01/2024',
            'owner' => 'may2024',
            'comment' => 'mikhmon',
        ),
    ),
    array(
        array(
            'name' => 'may/01/2024-|-09:00:00-|-partial-|-500-|-10.0.0.1-|-CC-|-5d-|-05-JOURS-|-vc-partial',
            'source' => 'may/01/2024',
            'owner' => 'may2024',
            'comment' => 'mikhmon',
        ),
        array(
            'name' => 'dec/20/2024-|-12:00:00-|-complete-|-1000-|-10.0.0.4-|-DD-|-1w-|-01-SEMAINE-|-vc-complete',
            'source' => 'dec/20/2024',
            'owner' => 'dec2024',
            'comment' => 'mikhmon',
        ),
    )
);
$partialReport = mikhmon_report_sales_for_month($partialApi, 'jun2026', 'jun/13/2026');
if ($partialReport['month_key'] !== 'dec2024') {
    fwrite(STDERR, 'partial RouterOS comment filters must be completed by the unfiltered script scan' . PHP_EOL);
    exit(1);
}
if ((int) $partialApi->timeout !== 3) {
    fwrite(STDERR, 'report scans must restore the original RouterOS API timeout' . PHP_EOL);
    exit(1);
}

echo "report_latest_sales_month_test passed\n";
