<?php
namespace Civi\Cv\Command;

use Civi\Cv\Encoder;
use Civi\Cv\Json;
use Civi\Cv\SiteConfigReader;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

class BaseCommand extends Command {

  protected function configureBootOptions() {
    $this->addOption('level', NULL, InputOption::VALUE_REQUIRED, 'Bootstrap level (classloader,settings,full)', 'full');
    $this->addOption('test', 't', InputOption::VALUE_NONE, 'Bootstrap the test database (CIVICRM_UF=UnitTests)');
  }

  protected function boot(InputInterface $input, OutputInterface $output) {
    if ($input->hasOption('test') && $input->getOption('test')) {
      putenv('CIVICRM_UF=UnitTests');
      $_ENV['CIVICRM_UF'] = 'UnitTests';
    }

    if ($input->hasOption('level') && $input->getOption('level') !== 'full') {
      \Civi\Cv\Bootstrap::singleton()->boot(array(
        'prefetch' => FALSE,
      ));
    }
    else {
      \Civi\Cv\Bootstrap::singleton()->boot();
      \CRM_Core_Config::singleton();
      \CRM_Utils_System::loadBootStrap(array(), FALSE);
    }
  }

  /**
   * @param \Symfony\Component\Console\Input\InputInterface $input
   * @param \Symfony\Component\Console\Output\OutputInterface $output
   * @param $result
   */
  protected function sendResult(InputInterface $input, OutputInterface $output, $result) {
    $output->writeln(Encoder::encode($result, $input->getOption('out')));
  }

}
