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

        $this['page'] = function ($app) {
            return new Page($app);
        };

        $pageConverter = function ($urlPath) {
            /** @var Page $page */
            $page = $this['page'];
            $page->setPath($urlPath);

            return $page;
        };

        $this->get('{page}', array( $this, 'getResponse' ))
            ->assert('page', '.*')
            ->convert('page', $pageConverter);

        $this->error(array( $this, 'handleError' ));

        foreach ($values as $key => $value) {
            $this[$key] = $value;
        }
    }

    /**
     * @param Application $app
     * @param Request     $request
     * @param Page        $page
     *
     * @return Response
     */
    public function getResponse(Application $app, Request $request, $page)
    {
        if (! $page->exists || empty($page->content)) {
            $app->abort(404);
        }

        $response = new Response();
        $response->setPublic();
        $date = new \DateTime();
        $date->setTimestamp($page->modified);
        $response->setLastModified($date);

        if ($response->isNotModified($request)) {
            return $response;
        }

        $context = array_merge(
            $app->getDefaultContext(),
            array(
                'page'          => $page,
                'content'       => $app->fetchContent($page),
                'is_front_page' => empty($page) && empty($name),
            )
        );

        if ($app['template.pjax'] && $request->headers->get('X-Pjax')) {
            return $this->render($app['template.pjax'], $context, $response);
        }

        return $app->render($app['template.index'], $context, $response);
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

    public function fetchContent($page)
    {
        if (empty($this['cache.options'])) {
            return $this->defaultTransform($page->content);
        }

        /** @var CacheProvider $cache */
        $cache      = $this['cache'];
        $key        = str_ireplace('/', '-', $page->name);
        $cache_data = $cache->fetch($key);

        if (empty($cache_data['content']) || $cache_data['modified'] != $page->modified) {
            $content = $this->defaultTransform($page->content);
            $cache->save($key, array( 'content' => $content, 'modified' => $page->modified ));
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

    public function handleError(HttpException $exception, $code)
    {
        if ($this['debug']) {
            return null; // to get error message and stack trace rather than templated 404
        }

        /** @var Page $page */
        $page = $this['page'];
        $page->setPath('404');
        $page->title    = $code;
        $page->subtitle = 'error';
        $context        = array_merge(
            $this->getDefaultContext(),
            array(
                'page'    => $page,
                'content' => $this->fetchContent($page),
            )
        );

        return $this->render('index.twig', $context);
    }
}