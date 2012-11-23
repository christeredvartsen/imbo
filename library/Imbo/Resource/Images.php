<?php
/**
 * Imbo
 *
 * Copyright (c) 2011-2012, Christer Edvartsen <cogo@starzinger.net>
 *
 * Permission is hereby granted, free of charge, to any person obtaining a copy
 * of this software and associated documentation files (the "Software"), to
 * deal in the Software without restriction, including without limitation the
 * rights to use, copy, modify, merge, publish, distribute, sublicense, and/or
 * sell copies of the Software, and to permit persons to whom the Software is
 * furnished to do so, subject to the following conditions:
 *
 * * The above copyright notice and this permission notice shall be included in
 *   all copies or substantial portions of the Software.
 *
 * THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 * IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 * FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 * AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 * LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING
 * FROM, OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS
 * IN THE SOFTWARE.
 *
 * @package Resources
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011-2012, Christer Edvartsen <cogo@starzinger.net>
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/imbo/imbo
 */

namespace Imbo\Resource;

use Imbo\Http\Request\RequestInterface,
    Imbo\Resource\Images\Query,
    Imbo\Resource\Images\QueryInterface,
    Imbo\EventManager\EventInterface,
    Imbo\EventManager\EventManager,
    Imbo\EventListener\ListenerInterface,
    Imbo\Container,
    Imbo\ContainerAware,
    DateTime;

/**
 * Images resource
 *
 * This resource will let users fetch images based on queries. The following query parameters can
 * be used:
 *
 * page     => Page number. Defaults to 1
 * limit    => Limit to a number of images pr. page. Defaults to 20
 * metadata => Whether or not to include metadata pr. image. Set to 1 to enable
 * query    => urlencoded json data to use in the query
 * from     => Unix timestamp to fetch from
 * to       => Unit timestamp to fetch to
 *
 * @package Resources
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @copyright Copyright (c) 2011-2012, Christer Edvartsen <cogo@starzinger.net>
 * @license http://www.opensource.org/licenses/mit-license MIT License
 * @link https://github.com/imbo/imbo
 */
class Images implements ContainerAware, ResourceInterface, ListenerInterface {
    /**
     * @var Container
     */
    private $container;

    /**
     * Query instance
     *
     * @var Imbo\Resource\Images\QueryInterface
     */
    private $query;

    /**
     * {@inheritdoc}
     */
    public function setContainer(Container $container) {
        $this->container = $container;
    }

    /**
     * Fetch a query instance
     *
     * @return QueryInterface
     */
    public function getQuery() {
        if ($this->query === null) {
            $this->query = new Query();
        }

        return $this->query;
    }

    /**
     * Set a query instance
     *
     * @param QueryInterface $query A query instance
     * @return ImagesResource
     */
    public function setQuery(QueryInterface $query) {
        $this->query = $query;

        return $this;
    }

    /**
     * {@inheritdoc}
     */
    public function getAllowedMethods() {
        return array(
            RequestInterface::METHOD_GET,
            RequestInterface::METHOD_HEAD,
        );
    }

    /**
     * {@inheritdoc}
     */
    public function attach(EventManager $manager) {
        $manager->attach('images.get', array($this, 'get'))
                ->attach('images.head', array($this, 'head'));
    }

    /**
     * Handle GET requests
     *
     * @param EventInterface $event The current event
     */
    public function get(EventInterface $event) {
        $request = $event->getRequest();
        $response = $event->getResponse();
        $database = $event->getDatabase();

        $publicKey = $request->getPublicKey();

        $responseHeaders = $response->getHeaders();

        // Fetch the last modification date of the current user
        $lastModified = $this->formatDate($database->getLastModified($publicKey));

        // Generate ETag based on the last modification date and add to the response headers
        $etag = '"' . md5($lastModified) . '"';
        $responseHeaders->set('ETag', $etag);
        $responseHeaders->set('Last-Modified', $lastModified);

        $query = $this->getQuery();
        $params = $request->getQuery();

        if ($params->has('page')) {
            $query->page($params->get('page'));
        }

        if ($params->has('limit')) {
            $query->limit($params->get('limit'));
        }

        if ($params->has('metadata')) {
            $query->returnMetadata($params->get('metadata'));
        }

        if ($params->has('from')) {
            $query->from($params->get('from'));
        }

        if ($params->has('to')) {
            $query->to($params->get('to'));
        }

        if ($params->has('query')) {
            $data = json_decode($params->get('query'), true);

            if (is_array($data)) {
                $query->metadataQuery($data);
            }
        }

        $images = $database->getImages($publicKey, $query);

        foreach ($images as &$image) {
            $image['added']   = $this->formatDate(new DateTime('@' . $image['added']));
            $image['updated'] = $this->formatDate(new DateTime('@' . $image['updated']));
        }

        $response->setBody($images);
    }

    /**
     * Handle HEAD requests
     *
     * @param EventInterface $event The current event
     */
    public function head(EventInterface $event) {
        $this->get($event);

        // Remove body from the response, but keep everything else
        $event->getResponse()->setBody(null);
    }

    /**
     * Wrapper around the dateformatter
     *
     * @param DateTime $date A DateTime instance
     * @return string A formatted date
     */
    private function formatDate($date) {
        return $this->container->get('dateFormatter')->formatDate($date);
    }
}
