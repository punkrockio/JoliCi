<?php
/*
 * This file is part of JoliCi.
 *
 * (c) Joel Wurtz <jwurtz@jolicode.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Joli\JoliCi\BuildStrategy;

use Joli\JoliCi\Build;
use Joli\JoliCi\Builder\DockerfileBuilder;
use Joli\JoliCi\Filesystem\Filesystem;
use Joli\JoliCi\Matrix;
use Joli\JoliCi\Naming;
use Symfony\Component\Yaml\Yaml;

/**
 * TravisCi implementation for build
 *
 * A project must have a .travis.yml file
 *
 * @author Joel Wurtz <jwurtz@jolicode.com>
 */
class TravisCiBuildStrategy implements BuildStrategyInterface
{
    private $languageVersionKeyMapping = array(
        'ruby' => 'rvm',
    );

    private $defaults = array(
        'php' => array(
            'before_install' => array(),
            'install'        => array('composer install'),
            'before_script'  => array(),
            'script'         => array('phpunit'),
            'env'            => array(),
        ),
        'ruby' => array(
            'before_install' => array(),
            'install'        => array('bundle install'),
            'before_script'  => array(),
            'script'         => array('bundle exec rake'),
            'env'            => array(),
        ),
        'node_js' => array(
            'before_install' => array(),
            'install'        => array('npm install'),
            'before_script'  => array(),
            'script'         => array('npm test'),
            'env'            => array(),
        ),
    );

    /**
     * @var DockerfileBuilder Builder for dockerfile
     */
    private $builder;

    /**
     * @var string Build path for project
     */
    private $buildPath;

    /**
     * @var Filesystem Filesystem service
     */
    private $filesystem;

    /**
     * @var \Joli\JoliCi\Naming Naming service to create docker name for images
     */
    private $naming;

    /**
     * @param DockerfileBuilder $builder    Twig Builder for Dockerfile
     * @param string            $buildPath  Directory where builds are created
     * @param Naming            $naming     Naming service
     * @param Filesystem        $filesystem Filesystem service
     */
    public function __construct(DockerfileBuilder $builder, $buildPath, Naming $naming, Filesystem $filesystem)
    {
        $this->builder    = $builder;
        $this->buildPath  = $buildPath;
        $this->naming     = $naming;
        $this->filesystem = $filesystem;
    }

    /**
     * {@inheritdoc}
     */
    public function getBuilds($directory)
    {
        $builds     = array();
        $config     = Yaml::parse(file_get_contents($directory.DIRECTORY_SEPARATOR.".travis.yml"));
        $matrix     = $this->createMatrix($config);
        $timezone   = ini_get('date.timezone');

        foreach ($matrix->compute() as $possibility) {
            $parmeters   = array(
                'language' => $possibility['language'],
                'version' => $possibility['version'],
                'environment' => $possibility['environment'],
            );

            $description = sprintf('%s = %s', $possibility['language'], $possibility['version']);

            if ($possibility['environment'] !== null) {
                $description .= sprintf(', Environment: %s', json_encode($possibility['environment']));
            }

            $builds[] = new Build($this->naming->getProjectName($directory), $this->getName(), $this->naming->getUniqueKey($parmeters), array(
                'language'       => $possibility['language'],
                'version'        => $possibility['version'],
                'before_install' => $possibility['before_install'],
                'install'        => $possibility['install'],
                'before_script'  => $possibility['before_script'],
                'script'         => $possibility['script'],
                'env'            => $possibility['environment'],
                'timezone'       => $timezone,
                'origin'         => realpath($directory),
            ), $description);
        }

        return $builds;
    }

    /**
     * {@inheritdoc}
     */
    public function prepareBuild(Build $build)
    {
        $parameters = $build->getParameters();
        $origin     = $parameters['origin'];
        $target     = $this->buildPath.DIRECTORY_SEPARATOR.$build->getDirectory();

        // First mirroring target
        $this->filesystem->mirror($origin, $target, null, array(
            'delete' => true,
            'override' => true,
        ));

        // Create dockerfile
        $this->builder->setTemplateName(sprintf("%s/Dockerfile-%s.twig", $parameters['language'], $parameters['version']));
        $this->builder->setVariables($parameters);
        $this->builder->setOutputName('Dockerfile');
        $this->builder->writeOnDisk($target);
    }

    /**
     * {@inheritdoc}
     */
    public function getName()
    {
        return "TravisCi";
    }

    /**
     * {@inheritdoc}
     */
    public function supportProject($directory)
    {
        return file_exists($directory.DIRECTORY_SEPARATOR.".travis.yml") && is_file($directory.DIRECTORY_SEPARATOR.".travis.yml");
    }

    /**
     * Get command lines to add for a configuration value in .travis.yml file
     *
     * @param array  $config   Configuration of travis ci parsed
     * @param string $language Language for getting the default value if no value is set
     * @param string $key      Configuration key
     *
     * @return array A list of command to add to Dockerfile
     */
    private function getConfigValue($config, $language, $key)
    {
        if (!isset($config[$key]) || empty($config[$key])) {
            if (isset($this->defaults[$language][$key])) {
                return $this->defaults[$language][$key];
            }

            return array();
        }

        if (!is_array($config[$key])) {
            return array($config[$key]);
        }

        return $config[$key];
    }

    /**
     * Create matrix of build
     *
     * @param array $config
     *
     * @return Matrix
     */
    protected function createMatrix($config)
    {
        $language         = isset($config['language']) ? $config['language'] : 'ruby';
        $versionKey       = isset($this->languageVersionKeyMapping[$language]) ? $this->languageVersionKeyMapping[$language] : $language;
        $environmentLines = $this->getConfigValue($config, $language, "env");
        $environnements   = array();

        // Parsing environnements
        foreach ($environmentLines as $environmentLine) {
            $environnements[] = $this->parseEnvironmentVariables($environmentLine);
        }

        $matrix = new Matrix();
        $matrix->setDimension('language', array($language));
        $matrix->setDimension('environment', $environnements);
        $matrix->setDimension('version', $config[$versionKey]);
        $matrix->setDimension('before_install', array($this->getConfigValue($config, $language, 'before_install')));
        $matrix->setDimension('install', array($this->getConfigValue($config, $language, 'install')));
        $matrix->setDimension('before_script', array($this->getConfigValue($config, $language, 'before_script')));
        $matrix->setDimension('script', array($this->getConfigValue($config, $language, 'script')));

        return $matrix;
    }

    /**
     * Parse an environnement line from Travis to return an array of variables
     *
     * Transform:
     *   "A=B C=D"
     * Into:
     *   array('a' => 'b', 'c' => 'd')
     *
     * @param $environmentLine
     * @return array
     */
    private function parseEnvironmentVariables($environmentLine)
    {
        $variables     = array();
        $variableLines = explode(' ', $environmentLine ?: '');

        foreach ($variableLines as $variableLine) {
            if (!empty($variableLine)) {
                list($key, $value) = explode('=', $variableLine);

                $variables[$key] = $value;
            }
        }

        return $variables;
    }
}
