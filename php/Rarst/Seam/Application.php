<?php
namespace Rarst\Seam;

use CHH\Silex\CacheServiceProvider;
use Doctrine\Common\Cache\CacheProvider;
use Silex\Application\TwigTrait;
use Silex\Provider\TwigServiceProvider;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;

class Application extends \Silex\Application
{
    use TwigTrait;

    public function __construct($values = array())
    {
        parent::__construct();

        // paths relative to index.php
        $this['theme']          = 'theme';
        $this['content']        = 'content';
        $this['template.index'] = 'index.twig';
        $this['template.pjax']  = null;
        $this['markdown.class'] = 'Michelf\Markdown';

        $this->register(new TwigServiceProvider(), array( 'twig.path' => $this['theme'] ));
        $this->register(new CacheServiceProvider());

        /** @noinspection PhpParamsInspection */
        $this->get('{page}', array( $this, 'getResponse' ))
            ->assert('page', '.*')
            ->convert(
                'page',
                function ($args) {
                    return array_filter(explode('/', $args));
                }
            );

        $this->error(array( $this, 'handleError' ));

        foreach ($values as $key => $value) {
            $this[$key] = $value;
        }
    }

    /**
     * @param Application $app
     * @param Request     $request
     * @param array       $page
     *
     * @return Response
     */
    public function getResponse(Application $app, Request $request, $page)
    {
        if (empty($page)) {
            $page_data = $app->parseFile($app['content'] . '/index.md');
        } else {
            $name = array_pop($page);

            // prevent technical 404.md from being treated as normal page
            if (404 == $name && empty($page)) {
                $app->abort(404);
            }

            $path  = $app['content'] . '/' . implode('/', $page) . '/' . $name;
            $index = $path . '/index.md';
            $file  = $path . '.md';

            if (file_exists($index)) {
                $page_data = $app->parseFile($index);
            } elseif (file_exists($file)) {
                $page_data = $app->parseFile($file);
            } else {
                $app->abort(404);
            }
        }

        if (empty($page_data['content'])) {
            $app->abort(404);
        }

        $response = new Response();
        $response->setPublic();
        $date = new \DateTime();
        $date->setTimestamp($page_data['modified']);
        $response->setLastModified($date);

        if ($response->isNotModified($request)) {
            return $response;
        }

        $context = array_merge(
            $app->getDefaultContext(),
            array(
                'content'       => $app->fetchContent($page_data),
                'meta'          => $page_data['meta'],
                'is_front_page' => empty($page) && empty($name),
            )
        );

        if ($app['template.pjax'] && $request->headers->get('X-Pjax')) {
            return $this->render($app['template.pjax'], $context, $response);
        }

        return $app->render($app['template.index'], $context, $response);
    }

    public function parseFile($path)
    {
        if (! file_exists($path)) {
            $this->abort(404);
        }

        $file_content  = file_get_contents($path);
        $meta          = array();
        $content       = $file_content;
        $comment_open  = stripos($file_content, '<!--');
        $comment_close = stripos($file_content, '-->');
        $name          = trim(substr($path, strlen($this['content'])), '/');
        $modified      = filemtime($path);

        if (0 === $comment_open && $comment_close) {

            $meta    = parse_ini_string(substr($file_content, 4, $comment_close - 5));
            $content = substr($file_content, $comment_close + 3);
        }

        return compact('meta', 'content', 'name', 'modified');
    }

    public function getDefaultContext()
    {
        /** @var Request $request */
        $request = $this['request'];
        $context = array(
            'base_url'  => $request->getSchemeAndHttpHost(),
            'theme_url' => $request->getUriForPath('/' . $this['theme']),
        );

        return $context;
    }

    public function fetchContent($page_data)
    {
        if (empty($this['cache.options'])) {
            return $this->defaultTransform($page_data['content']);
        }

        /** @var CacheProvider $cache */
        $cache      = $this['cache'];
        $key        = str_ireplace('/', '-', $page_data['name']);
        $cache_data = $cache->fetch($key);

        if (empty($cache_data['content']) || $cache_data['modified'] != $page_data['modified']) {
            $content = $this->defaultTransform($page_data['content']);
            $cache->save($key, array( 'content' => $content, 'modified' => $page_data['modified'] ));
        } else {
            $content = $cache_data['content'];
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
        return call_user_func(array( $this['markdown.class'], 'defaultTransform' ), $markdown);
    }

    public function handleError(HttpException $e, $code)
    {
        if ($this['debug']) {
            return null; // to get error message and stack trace rather than templated 404
        }

        $page_data = $this->parseFile($this['content'] . '/404.md');
        $content   = $this->fetchContent($page_data);
        $context   = array_merge(
            $this->getDefaultContext(),
            array(
                'content' => $content,
                'meta'    => array( 'title' => $code, 'subtitle' => 'error' ),
            )
        );

        return $this->render('index.twig', $context);
    }
}