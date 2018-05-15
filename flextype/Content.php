<?php

/**
 * @package Flextype
 *
 * @author Sergey Romanenko <awilum@yandex.ru>
 * @link http://flextype.org
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace Flextype;

use Flextype\Component\{Arr\Arr, Http\Http, Filesystem\Filesystem, Event\Event, Registry\Registry};
use Symfony\Component\Yaml\Yaml;
use ParsedownExtra as Markdown;
use Thunder\Shortcode\ShortcodeFacade;
use Thunder\Shortcode\Shortcode\ShortcodeInterface;

class Content
{
    /**
     * An instance of the Cache class
     *
     * @var object
     * @access protected
     */
    protected static $instance = null;

    /**
     * Markdown Object
     *
     * @var object
     * @access private
     */
    private static $markdown = null;

    /**
     * Shortcode object
     *
     * @var object
     * @access private
     */
    private static $shortcode = null;

    /**
     * Current page array
     *
     * @var array
     * @access protected
     */
    private static $page = [];

    /**
     * Protected constructor since this is a static class.
     *
     * @access  protected
     */
    protected function __construct()
    {
        static::init();
    }

    /**
     * Init Pages
     *
     * @access protected
     * @return void
     */
    protected static function init() : void
    {
        // Event: The page is not processed and not sent to the display.
        Event::dispatch('onPageBeforeRender');

        // Create Markdown Parser object
        Content::$markdown = new Markdown();

        // Create Shortcode Parser object
        Content::$shortcode = new ShortcodeFacade();

        // Register default shortcodes
        Content::registerDefaultShortcodes();

        // Set current requested page data to $page array
        Content::$page = Content::getPage(Http::getUriString());

        // Display page for current requested url
        Content::displayCurrentPage();

        // Event: The page has been fully processed and sent to the display.
        Event::dispatch('onPageAfterRender');
    }

    /**
     * Get current page
     */
    public static function getCurrentPage() : array
    {
        return Content::$page;
    }

    /**
     * Page finder
     *
     * @access  public
     * @param string $url
     * @param bool   $url_abs
     */
    public static function finder(string $url = '', bool $url_abs = false) : string
    {
        // If url is empty that its a homepage
        if ($url_abs) {
            if ($url) {
                $file = $url;
            } else {
                $file = PAGES_PATH . '/' . Registry::get('site.pages.main') . '/' . 'page.md';
            }
        } else {
            if ($url) {
                $file = PAGES_PATH . '/' . $url . '/page.md';
            } else {
                $file = PAGES_PATH . '/' . Registry::get('site.pages.main') . '/' . 'page.md';
            }
        }

        // Get 404 page if file not exists
        if (Filesystem::fileExists($file)) {
            $file = $file;
        } else {
            $file = PAGES_PATH . '/404/page.md';
            Http::setResponseStatus(404);
        }

        return $file;
    }

    /**
     * Get page
     */
    public static function getPage(string $url = '', bool $raw = false, bool $url_abs = false) : array
    {
        $file = Content::finder($url, $url_abs);

        if ($raw) {
            Content::$page = Content::processPageRaw($file);
            Event::dispatch('onPageContentRawAfter');
        } else {
            Content::$page = Content::processPage($file);
            Event::dispatch('onPageContentAfter');
        }

        return Content::$page;
    }

    /**
     * Get Pages
     */
    public static function getPages(string $url = '', bool $raw = false, string $order_by = 'date', string $order_type = 'DESC', int $offset = null, int $length = null)
    {
        // Pages array where founded pages will stored
        $pages = [];

        // Get pages for $url
        // If $url is empty then we want to have a list of pages for /pages dir.
        if ($url == '') {

            // Get pages list
            $pages_list = Filesystem::getFilesList(PAGES_PATH, 'md');

            // Create pages array from pages list
            foreach ($pages_list as $key => $page) {
                $pages[$key] = static::getPage($page, $raw, true);
            }

        } else {

            // Get pages list
            $pages_list = Filesystem::getFilesList(PAGES_PATH . '/' . $url, 'md');

            // Create pages array from pages list and ignore current requested page
            foreach ($pages_list as $key => $page) {
                if (strpos($page, $url.'/page.md') !== false) {
                    // ignore ...
                } else {
                    $pages[$key] = static::getPage($page, $raw, true);
                }
            }

        }

        // Sort and Slice pages if $raw === false
        if (!$raw) {
            $pages = Arr::sort($pages, $order_by, $order_type);

            if ($offset !== null && $length !== null) {
                $pages = array_slice($pages, $offset, $length);
            }
        }

        // Return pages array
        return $pages;
    }

    /**
     * Returns $shortcode object
     *
     * @access public
     * @return object
     */
    public static function shortcode() : ShortcodeFacade
    {
        return Content::$shortcode;
    }

    public static function processPageRaw(string $file) : string
    {
        return trim(Filesystem::getFileContent($file));
    }

    public static function processPage(string $file) : array
    {
        // Get page from file
        $page = trim(Filesystem::getFileContent($file));

        // Create $page_frontmatter and $page_content
        $page = explode('---', $page, 3);
        $page_frontmatter = $page[1];
        $page_content     = $page[2];

        // Create empty $_page
        $_page = [];

        // Process $page_frontmatter with YAML and Shortcodes parsers
        $_page = Yaml::parse(Content::processContentShortcodes($page_frontmatter));

        // Create page url item
        $url = str_replace(PAGES_PATH, Http::getBaseUrl(), $file);
        $url = str_replace('page.md', '', $url);
        $url = str_replace('.md', '', $url);
        $url = str_replace('\\', '/', $url);
        $url = str_replace('///', '/', $url);
        $url = str_replace('//', '/', $url);
        $url = str_replace('http:/', 'http://', $url);
        $url = str_replace('https:/', 'https://', $url);
        $url = str_replace('/'.Registry::get('site.pages.main'), '', $url);
        $url = rtrim($url, '/');
        $_page['url'] = $url;

        // Create page slug item
        $url = str_replace(Http::getBaseUrl(), '', $url);
        $url = ltrim($url, '/');
        $url = rtrim($url, '/');
        $_page['slug'] = str_replace(Http::getBaseUrl(), '', $url);

        // Create page date item
        $_page['date'] = $result_page['date'] ?? date(Registry::get('site.date_format'), filemtime($file));

        // Create page content item with $page_content
        $_page['content'] = Content::processContent($page_content);

        // Return page
        return $_page;
    }

    public static function processContentShortcodes(string $content) : string
    {
        return Content::shortcode()->process($content);
    }

    public static function processContentMarkdown(string $content) : string
    {
        return Content::$markdown->text($content);
    }

    public static function processContent(string $content) : string
    {
        $content = Content::processContentShortcodes($content);
        $content = Content::processContentMarkdown($content);

        return $content;
    }

    /**
     * Register default shortcodes
     *
     * @access protected
     */
    protected static function registerDefaultShortcodes() : void
    {
        Content::shortcode()->addHandler('site_url', function() {
            return Http::getBaseUrl();
        });

        Content::shortcode()->addHandler('block', function(ShortcodeInterface $s) {
            return $s->getParameter('name');
        });
    }

    /**
     * Display current page
     *
     * @access protected
     * @return void
     */
    protected static function displayCurrentPage() : void
    {
        Themes::template(empty(Content::$page['template']) ? 'templates/default' : 'templates/' . Content::$page['template'])
            ->assign('page', Content::$page, true)
            ->display();
    }

    /**
     * Return the Content instance.
     * Create it if it's not already created.
     *
     * @access public
     * @return object
     */
    public static function instance()
    {
        return !isset(self::$instance) and self::$instance = new Content();
    }
}