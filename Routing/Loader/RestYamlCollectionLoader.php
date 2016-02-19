<?php

/*
 * This file is part of the FOSRestBundle package.
 *
 * (c) FriendsOfSymfony <http://friendsofsymfony.github.com/>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace FOS\RestBundle\Routing\Loader;

use FOS\RestBundle\Routing\RestRouteCollection;
use Symfony\Component\Config\FileLocatorInterface;
use Symfony\Component\Config\Resource\FileResource;
use Symfony\Component\Routing\Loader\YamlFileLoader;
use Symfony\Component\Routing\RouteCollection;
use Symfony\Component\Yaml\Exception\ParseException;
use Symfony\Component\Yaml\Parser as YamlParser;

/**
 * RestYamlCollectionLoader YAML file collections loader.
 */
class RestYamlCollectionLoader extends YamlFileLoader
{
    private static $availableKeys = array(
        'resource', 'type', 'prefix', 'path', 'host', 'schemes', 'methods', 'defaults', 'requirements', 'options', 'condition',
        'parent', 'name_prefix'
    );

    protected $collectionParents = array();
    private $processor;
    private $includeFormat;
    private $formats;
    private $defaultFormat;

    private $yamlParser;

    /**
     * Initializes yaml loader.
     *
     * @param FileLocatorInterface $locator
     * @param RestRouteProcessor   $processor
     * @param bool                 $includeFormat
     * @param string[]             $formats
     * @param string               $defaultFormat
     */
    public function __construct(
        FileLocatorInterface $locator,
        RestRouteProcessor $processor,
        $includeFormat = true,
        array $formats = array(),
        $defaultFormat = null
    ) {
        parent::__construct($locator);

        $this->processor = $processor;
        $this->includeFormat = $includeFormat;
        $this->formats = $formats;
        $this->defaultFormat = $defaultFormat;
    }

    /**
     * {@inheritdoc}
     */
    public function load($file, $type = null)
    {
        $path = $this->locator->locate($file);

        if (!stream_is_local($path)) {
            throw new \InvalidArgumentException(sprintf('This is not a local file "%s".', $path));
        }

        if (!file_exists($path)) {
            throw new \InvalidArgumentException(sprintf('File "%s" not found.', $path));
        }

        if (null === $this->yamlParser) {
            $this->yamlParser = new YamlParser();
        }

        try {
            $parsedConfig = $this->yamlParser->parse(file_get_contents($path));
        } catch (ParseException $e) {
            throw new \InvalidArgumentException(sprintf('The file "%s" does not contain valid YAML.', $path), 0, $e);
        }

        $collection = new RouteCollection();
        $collection->addResource(new FileResource($path));

        // empty file
        if (null === $parsedConfig) {
            return $collection;
        }

        // not an array
        if (!is_array($parsedConfig)) {
            throw new \InvalidArgumentException(sprintf('The file "%s" must contain a YAML array.', $path));
        }

        foreach ($parsedConfig as $name => $config) {
            $this->validate($config, $name, $path);

            if (isset($config['resource'])) {
                $this->parseResource($collection, $name, $config, $path, $file);
            } else {
                $this->parseRoute($collection, $name, $config, $path);
            }
        }

        return $collection;
    }

    protected function parseRoute(RouteCollection $collection, $name, array $config, $path)
    {
        if ($this->includeFormat) {
            // append format placeholder if not present
            if (false === strpos($config['path'], '{_format}')) {
                $config['path'] .= '.{_format}';
            }

            // set format requirement if configured globally
            if (!isset($config['requirements']['_format']) && !empty($this->formats)) {
                $config['requirements']['_format'] = implode('|', array_keys($this->formats));
            }
        }

        // set the default format if configured
        if (null !== $this->defaultFormat) {
            $config['defaults']['_format'] = $this->defaultFormat;
        }

        parent::parseRoute($collection, $name, $config, $path);
    }

    protected function parseResource(RouteCollection $collection, $name, array $config, $path, $file)
    {
        $namePrefix = isset($config['name_prefix']) ? $config['name_prefix'] : null;
        $parent = isset($config['parent']) ? $config['parent'] : null;
        $parents = ! empty($parent) ? $this->collectionParents[$parent] : array();

        /*
         * The following lines are the same of Symfony's YamlFileLoader::parseImport
         * Keep 'em in sync!
         */
        $type = isset($config['type']) ? $config['type'] : null;
        $prefix = isset($config['prefix']) ? $config['prefix'] : '';
        $defaults = isset($config['defaults']) ? $config['defaults'] : array();
        $requirements = isset($config['requirements']) ? $config['requirements'] : array();
        $options = isset($config['options']) ? $config['options'] : array();
        $host = isset($config['host']) ? $config['host'] : null;
        $condition = isset($config['condition']) ? $config['condition'] : null;
        $schemes = isset($config['schemes']) ? $config['schemes'] : null;
        $methods = isset($config['methods']) ? $config['methods'] : null;

        $this->setCurrentDir(dirname($path));

        $subCollection = $this->processor->importResource($this, $config['resource'], $parents, $prefix, $namePrefix, $type, dirname($path));

        if ($subCollection instanceof RestRouteCollection) {
            $parents[] = ($prefix ? $prefix.'/' : '').$subCollection->getSingularName();
            $prefix = null;
            $namePrefix = null;

            $this->collectionParents[$name] = $parents;
        }

        /* @var $subCollection RouteCollection */
        $subCollection->addPrefix($prefix);
        if (null !== $host) {
            $subCollection->setHost($host);
        }
        if (null !== $condition) {
            $subCollection->setCondition($condition);
        }
        if (null !== $schemes) {
            $subCollection->setSchemes($schemes);
        }
        if (null !== $methods) {
            $subCollection->setMethods($methods);
        }
        $subCollection->addDefaults($defaults);
        $subCollection->addRequirements($requirements);
        $subCollection->addOptions($options);
        $subCollection = $this->addParentNamePrefix($subCollection, $namePrefix);

        $collection->addCollection($subCollection);
    }

    protected function validate($config, $name, $path)
    {
        if (!is_array($config)) {
            throw new \InvalidArgumentException(sprintf('The definition of "%s" in "%s" must be a YAML array.', $name, $path));
        }
        if ($extraKeys = array_diff(array_keys($config), self::$availableKeys)) {
            throw new \InvalidArgumentException(sprintf(
                'The routing file "%s" contains unsupported keys for "%s": "%s". Expected one of: "%s".',
                $path, $name, implode('", "', $extraKeys), implode('", "', self::$availableKeys)
            ));
        }
        if (isset($config['resource']) && isset($config['path'])) {
            throw new \InvalidArgumentException(sprintf(
                'The routing file "%s" must not specify both the "resource" key and the "path" key for "%s". Choose between an import and a route definition.',
                $path, $name
            ));
        }
        if (!isset($config['resource']) && isset($config['type'])) {
            throw new \InvalidArgumentException(sprintf(
                'The "type" key for the route definition "%s" in "%s" is unsupported. It is only available for imports in combination with the "resource" key.',
                $name, $path
            ));
        }
        if (!isset($config['resource']) && !isset($config['path'])) {
            throw new \InvalidArgumentException(sprintf(
                'You must define a "path" for the route "%s" in file "%s".',
                $name, $path
            ));
        }

        if (isset($config['resource']) && isset($config['parent'])) {
            $parent = $config['parent'];

            if (!isset($this->collectionParents[$parent])) {
                throw new \InvalidArgumentException(sprintf('Cannot find parent resource with name %s', $parent));
            }
        }
    }

    /**
     * {@inheritdoc}
     */
    public function supports($resource, $type = null)
    {
        return is_string($resource) &&
            'yml' === pathinfo($resource, PATHINFO_EXTENSION) &&
            'rest' === $type;
    }

    /**
     * Adds a name prefix to the route name of all collection routes.
     *
     * @param RouteCollection $collection Route collection
     * @param array           $namePrefix NamePrefix to add in each route name of the route collection
     *
     * @return RouteCollection
     */
    public function addParentNamePrefix(RouteCollection $collection, $namePrefix)
    {
        if (!isset($namePrefix) || ($namePrefix = trim($namePrefix)) === '') {
            return $collection;
        }

        $iterator = $collection->getIterator();

        foreach ($iterator as $key1 => $route1) {
            $collection->add($namePrefix.$key1, $route1);
            $collection->remove($key1);
        }

        return $collection;
    }
}
