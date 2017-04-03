<?php
namespace Civi\Cv\Command;

use Civi\Cv\Application;
use Civi\Cv\Encoder;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Filesystem\Filesystem;
use Civi\Pop\Pop;
use Faker;

class PopCommand extends BaseCommand {

  /**
   * @var array
   */
  var $defaults;

  /**
   * @param string|null $name
   */
  public function __construct($name = NULL) {
    $this->defaults = array('version' => 3);
    parent::__construct($name);
  }

  /**
   * Determine whether pop dependencies are available.
   *
   * @return array
   *   Array of messages.
   */
  public static function checkDependencies() {
    $r = array();
    if (!function_exists('yaml_parse_file')) {
      $r[] = 'Missing PHP-YAML extension (http://php.net/manual/en/book.yaml.php)';
    }
    return $r;
  }

  protected function configure() {
    $deps = static::checkDependencies();
    $suffix = $deps ? ' (unavailable)' : '';
    $suffixLong = "\nPop is not available due to dependency issues:\n   * " . implode("\n   * ", $deps) . "\n";
    $this
      ->setName('pop')
      ->addArgument('file', InputArgument::REQUIRED, 'YAML file with entities to populate')
      ->addOption('out', NULL, InputOption::VALUE_REQUIRED, 'Output format (' . implode(',', Encoder::getFormats()) . ')', Encoder::getDefaultFormat())
      ->setDescription('Populate a site with entities from a YAML file' . $suffix)
      ->setHelp('Populate a site with entities from a YAML file' . $suffix . '

Examples:
  cv pop contacts.yml

For documentation about the YAML file format, see:
  https://github.com/michaelmcandrew/pop
' . $suffixLong);
    parent::configureBootOptions();
  }

  protected function execute(InputInterface $input, OutputInterface $output) {
    $this->boot($input, $output);

    if (array() !== static::checkDependencies()) {
      $this->sendResult($input, $output, array(
        'is_error' => 1,
        'error_message' => 'Pop is not available due to dependency issues: ' . implode(', ', static::checkDependencies()),
      ));
      return 1;
    }

    $pop = new Pop($output);
    $pop->setInteractive($input->isInteractive());
    if ($input->getOption('out') != 'json-pretty') {
      $pop->setInteractive(0);
    }
    var_dump();
    $fs = new Filesystem();
    if ($fs->isAbsolutePath($input->getArgument('file'))) {
      $pop->process($input->getArgument('file'));
    }
    else {
      $pop->process($_SERVER['PWD'] . DIRECTORY_SEPARATOR . $input->getArgument('file'));
    }

    if ($input->getOption('out') != 'json-pretty') {
      $this->sendResult($input, $output, $pop->getSummary());
    }
  }

}
