<?php
namespace Civi\Cv\Command;

use Civi\Cv\Application;
use Civi\Cv\Encoder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Command for asking CiviCRM for the appropriate tarball to download.
 */
class UpgradeReportCommand extends BaseCommand {
  const DEFAULT_REPORT_URL = 'https://upgrade.civicrm.org/report';

  protected function configure() {
    $this
      ->setName('upgrade:report')
      ->setDescription('Notify civicrm.org of your upgrade success or failure')
      ->addOption('out', NULL, InputOption::VALUE_REQUIRED, 'Output format (' . implode(',', Encoder::getFormats()) . ')', Encoder::getDefaultFormat())
      ->addOption('name', NULL, InputOption::VALUE_REQUIRED, 'Specify the name to link the report to past reports on the same upgrade')
      ->addOption('started', NULL, InputOption::VALUE_NONE, 'Send a "started" report')
      ->addOption('started-time', NULL, InputOption::VALUE_REQUIRED, 'Send a "started" report with a specified timestamp')
      ->addOption('downloadurl', NULL, InputOption::VALUE_REQUIRED, 'Indicate the URL for the download attempt')
      ->addOption('downloaded', NULL, InputOption::VALUE_NONE, 'Send a "downloaded" report')
      ->addOption('downloaded-time', NULL, InputOption::VALUE_REQUIRED, 'Send a "downloaded" report with a specified timestamp')
      ->addOption('extracted', NULL, InputOption::VALUE_NONE, 'Send an "extracted" report')
      ->addOption('extracted-time', NULL, InputOption::VALUE_REQUIRED, 'Send an "extracted" report with a specified timestamp')
      ->addOption('upgraded', NULL, InputOption::VALUE_REQUIRED, 'Send an "upgraded" report (provide array of upgrade messages and version)')
      ->addOption('upgraded-time', NULL, InputOption::VALUE_REQUIRED, 'Send an "upgraded" report with a specified timestamp')
      ->addOption('finished', NULL, InputOption::VALUE_NONE, 'Send a "finished" report')
      ->addOption('finished-time', NULL, InputOption::VALUE_REQUIRED, 'Send a "finished" report with a specified timestamp')
      ->addOption('problem', NULL, InputOption::VALUE_REQUIRED, "Report a problem with the upgrade (if you haven't already reported that you started).  Value should be the stage where the problem occurred (download, extract, upgrade).")
      ->addOption('reporter', NULL, InputOption::VALUE_REQUIRED, "Your email address so you can be contacted with questions")
      ->setHelp('Notify civicrm.org of your upgrade success or failure

Examples:
  cv upgrade:report --started-time=1475079931 --downloaded

Returns a JSON object with the properties:
  name      The name under which the report was issued

');
    parent::configureBootOptions();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    // POST https://upgrade.civicrm.org/report
    // Content-type: application/x-www-form-urlencoded
    //
    // `name`          Always required.
    // `site_id`       An identifier of the site that carries over between upgrades
    // `reporter`      An email address for contacting someone.
    // `status`        'running', 'successful', or 'failed'
    // `stage`         Read-only value computed from timestamps.
    // `downloadUrl`   The URL used to setup [[WRITE-ONCE]]
    // `started`       Time (seconds since epoch) at which we generated the start report  [[WRITE-ONCE]]
    // `startReport`   JSON report from "System.get" [[WRITE-ONCE]]
    // `downloaded`    Time (seconds since epoch) at which the download completed[[WRITE-ONCE]]
    // `extracted`     Time (seconds since epoch) at which the tarball finished extraction [[WRITE-ONCE]]
    // `upgraded`      Time (seconds since epoch) at which the DB upgrade  [[WRITE-ONCE]]
    // `upgradeReport` List of upgrade messages [[WRITE-ONCE]]
    // `finished`      Time (seconds since epoch) at which we generated the finish report [[WRITE-ONCE]]
    // `finishReport`  JSON report from "System.get" [[WRITE-ONCE]]
    // `testsReport`   JSON report from a testing suite

    $opts = $input->getOptions();

    // Check for required fields, etc.
    $reportProblems = $this->checkReport($opts);

    // For now, just throw an exception if the report is bad.
    if (!empty($reportProblems)) {
      throw new \RuntimeException(implode("\n", $reportProblems));
    }

    // Set up identity of report
    $report = array(
      'siteId' => \Civi\Cv\Util\Cv::run("ev \"return md5('We need to talk about your TPS reports' . CIVICRM_SITE_KEY);\" --level=settings"),
      'cvVersion' => '@package_version@',
    );

    if ($opts['downloadurl']) {
      $report['downloadUrl'] = $opts['downloadurl'];
    }

    if ($opts['reporter']) {
      $report['reporter'] = $opts['reporter'];
    }

    if ($opts['started'] || $opts['started-time']) {
      $report['started'] = ($opts['started-time']) ?: time();
      $report['startReport'] = $this->systemReport();
      $report['name'] = ($opts['name']) ?: $this->createName($report);
    }

    if ($opts['problem']) {
      $report['isProblem'] = TRUE;
      switch ($opts['problem']) {
        case 'upgrade':
        case 'upgraded':
          $report['upgraded'] = ($opts['upgraded-time']) ?: time();

        case 'extract':
        case 'extracted':
          $report['extracted'] = ($opts['extracted-time']) ?: time();

        case 'download':
        case 'downloaded':
          $report['downloaded'] = ($opts['downloaded-time']) ?: time();
          break;
      }
      $report['name'] = ($opts['name']) ?: $this->createName($report);
      $report['failed'] = time();

      $report['problem'] = $this->systemReport();
    }
    elseif ($opts['name']) {
      $report['name'] = $opts['name'];
    }

    foreach (array(
      'downloaded',
      'extracted',
      'upgraded',
      'finished',
    ) as $r) {
      if ($opts[$r . 'time']) {
        $report[$r] = $opts[$r . 'time'];
      }
      elseif ($opts[$r]) {
        $report[$r] = time();
      }
    }

    if (!empty($opts['upgraded'])) {
      $report['upgradeReport'] = json_decode($opts['upgraded'], TRUE);
    }

    if ($opts['finished'] || $opts['finished-time']) {
      $report['finishReport'] = $this->systemReport();
    }

    // Send report
    $report['response'] = $this->reportToCivi($report);

    $this->sendResult($input, $output, $report);
  }

  /**
   * Generates a name to identify the report
   *
   * @param array $report
   *   Report information (or anything, really)
   * @return string
   *   The name to use
   */
  protected function createName($report) {
    return md5(json_encode($report) . uniqid() . rand() . rand() . rand());
  }

  /**
   * Get a system report if available
   *
   * @return array
   *   A system report.
   */
  protected function systemReport() {
    try {
      $report = \Civi\Cv\Util\Cv::run('api system.get');
    }
    catch (\Exception $e) {
      $report = array('error' => 'Could not produce report');
    }
    $vars = \Civi\Cv\Util\Cv::run('vars:show');
    $domain = empty($vars['CMS_URL']) ? NULL : preg_replace(";https?://;", '', $vars['CMS_URL']);
    $this->recursiveRedact($report, $domain);
    return $report;
  }

  protected function recursiveRedact(&$report, $domain) {
    foreach ($report as $k => &$v) {
      if (is_array($v)) {
        $this->recursiveRedact($v, $domain);
      }
      elseif ($v == "REDACTED") {
        unset($report[$k]);
      }
      elseif ($domain) {
        $v = preg_replace(";(https?://)?$domain;", 'REDACTEDURL/', $v);
      }
    }
  }

  /**
   * Check to see if the report contains necessary information.
   *
   * @param array $opts
   *   The options submitted to upgrade:report
   * @return array
   *   Message(s) about any problems found.
   */
  protected function checkReport($opts) {
    $probs = array();

    // Don't accept --start once the upgrade has gotten too far
    if ($opts['started'] || $opts['started-time']) {
      // Steps that occur after a useful start report could be produced:
      $tooLate = array(
        'extracted',
        'extracted-time',
        'upgraded',
        'upgraded-time',
        'finished',
        'finished-time',
      );
      foreach ($tooLate as $too) {
        if ($opts[$too]) {
          $probs[] = "You can't report a start once you have extracted or upgraded. Use --problem instead.";
        }
      }
    }

    // A --downloaded report needs a download URL.
    if (($opts['downloaded'] || $opts['downloaded-time']) && !$opts['downloadurl']) {
      $probs[] = 'You must specify the download URL as --downloadUrl.';
    }

    // Require --name if this upgrade has been reported already
    if (!($opts['started'] || $opts['started-time'] || $opts['problem']) && !$opts['name']) {
      $probs[] = 'Unless you are sending a start report (with --started, --started-time, or --problem), you must specify the report name (with --name)';
    }

    return $probs;
  }

  protected function reportToCivi($report) {
    foreach ($report as &$part) {
      if (is_array($part)) {
        $part = json_encode($part);
      }
    }
    $ch = curl_init(self::DEFAULT_REPORT_URL);
    curl_setopt($ch, CURLOPT_POSTFIELDS, $report);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, TRUE);
    $response = curl_exec($ch);
    curl_close($ch);
    return $response;
  }

}
