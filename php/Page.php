<?php
namespace Rarst\Seam;

use Doctrine\Common\Cache\CacheProvider;
use Mni\FrontYAML\Parser;

class Page
{
    protected $app;

    public $exists = false;
    public $name;
    public $modified;
    public $source;

    /**
     * @param Application $app
     */
    public function __construct($app)
    {
        $this->app = $app;
    }

    /**
     * @param string $urlPath
     *
     * @return bool
     */
    public function setPath($urlPath)
    {
        $pathParts = array_filter(explode('/', $urlPath));
        $path      = $this->getExistingPath($pathParts);

        if ($path) {
            $this->exists = $this->parseFile($path);
        }

        return $this->exists;
    }

    public function getExistingPath($pathParts)
    {
        $content = $this->app['content'];

        if (empty($pathParts) && file_exists($content . '/index.md')) {
            return $content . '/index.md';
        }

        $name = array_pop($pathParts);
        $path = $content . '/' . implode('/', $pathParts) . '/' . $name;

        $index = $path . '/index.md';
        if (file_exists($index)) {
            return $index;
        }

        $file = $path . '.md';
        if (file_exists($file)) {
            return $file;
        }

        return false;
    }

    public function parseFile($path)
    {
        if (! file_exists($path)) {
            return false;
        }

        $fileContent    = file_get_contents($path);
        $this->name     = trim(substr($path, strlen($this->app['content'])), '/');
        $this->modified = filemtime($path);

        /** @var Parser $parser */
        $parser   = $this->app['parser'];
        $document = $parser->parse($fileContent, false);
        $yaml     = $document->getYAML();

        if (is_array($yaml)) {

            foreach ($yaml as $key => $value) {
                $this->$key = $value;
            }
        }

        $this->source = $document->getContent();

        return true;
    }

    public function getContent()
    {
        if (empty($this->app['cache.options'])) {
            return $this->defaultTransform($this->source);
        }

        /** @var CacheProvider $cache */
        $cache     = $this->app['cache'];
        $key       = str_ireplace('/', '-', $this->name);
        $cacheData = $cache->fetch($key);

        if (empty($cacheData['content']) || $cacheData['modified'] !== $this->modified) {
            $content = $this->defaultTransform($this->source);
            $cache->save($key, array( 'content' => $content, 'modified' => $this->modified ));
        } else {
            $content = $cacheData['content'];
        }

        return $content;
    }

    /**
     * @param string $markdown
     *
     * @return string
     */
    public function defaultTransform($markdown)
    {
        return $this->app['markdown.parser']->parse($markdown);
    }
}