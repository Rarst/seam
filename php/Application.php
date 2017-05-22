<?php
namespace Rarst\Seam;

use CHH\Silex\CacheServiceProvider;
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
        $this['markdown.class'] = 'Michelf\MarkdownExtra';
        $this['site_title']      = 'Seam';

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
        if (! $page->exists || empty($page->source)) {
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
                'is_front_page' => 'index.md' === $page->name,
            )
        );

        if ($request->headers->get('X-Pjax')) {

            /** @var \Twig_Environment $twig */
            $twig           = $app['twig'];
            $template       = $twig->loadTemplate($app['template.index']);
            $context['app'] = $app;
            $title          = trim($template->renderBlock('title', $context));
            $content        = trim($template->renderBlock('content', $context));

            return $response->setContent($title . "\n" . $content);
        }

        return $app->render($app['template.index'], $context, $response);
    }

    public function getDefaultContext()
    {
        /** @var Request $request */
        $request = $this['request_stack']->getCurrentRequest();
        $context = array(
            'base_url'  => $request->getSchemeAndHttpHost(),
            'theme_url' => $request->getUriForPath('/' . $this['theme']),
        );

        return $context;
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
            array( 'page' => $page, )
        );

        return $this->render('index.twig', $context);
    }
}