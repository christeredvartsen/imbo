<?php
/**
 * This file is part of the Imbo package
 *
 * (c) Christer Edvartsen <cogo@starzinger.net>
 *
 * For the full copyright and license information, please view the LICENSE file that was
 * distributed with this source code.
 */

namespace Imbo;

use Imbo\Http\Request\Request,
    Imbo\Http\Response\Response,
    Imbo\EventListener\ListenerInterface,
    Imbo\EventManager\Event,
    Imbo\EventManager\EventManager,
    Imbo\Model\Error,
    Imbo\Exception\RuntimeException,
    Imbo\Exception\InvalidArgumentException,
    Imbo\Database\DatabaseInterface,
    Imbo\Storage\StorageInterface,
    Imbo\Http\Response\Formatter,
    Imbo\Resource\ResourceInterface,
    Imbo\EventListener\Initializer\InitializerInterface;

/**
 * Imbo application
 *
 * @author Christer Edvartsen <cogo@starzinger.net>
 * @package Core
 */
class Application {
    /**
     * Run the application
     */
    public function run(array $config) {
        // Request and response objects
        $request = Request::createFromGlobals();
        $response = new Response();
        $response->setPublic();
        $response->headers->set('X-Imbo-Version', Version::VERSION);

        // Database and storage adapters
        $database = $config['database'];

        if (is_callable($database) && !($database instanceof DatabaseInterface)) {
            $database = $database();
        }

        if (!$database instanceof DatabaseInterface) {
            throw new InvalidArgumentException('Invalid database adapter', 500);
        }

        $storage = $config['storage'];

        if (is_callable($storage) && !($storage instanceof StorageInterface)) {
            $storage = $storage();
        }

        if (!$storage instanceof StorageInterface) {
            throw new InvalidArgumentException('Invalid storage adapter', 500);
        }

        $router = new Router($config['routes']);

        // Create the event manager and the event template
        $eventManager = new EventManager();
        $event = new Event();
        $event->setArguments(array(
            'request' => $request,
            'response' => $response,
            'database' => $database,
            'storage' => $storage,
            'config' => $config,
            'manager' => $eventManager,
        ));
        $eventManager->setEventTemplate($event);

        // A date formatter helper
        $dateFormatter = new Helpers\DateFormatter();

        // Response formatters
        $formatters = array(
            'json' => new Formatter\JSON($dateFormatter),
            'xml'  => new Formatter\XML($dateFormatter),
        );
        $contentNegotiation = new Http\ContentNegotiation();

        // Collect event listener data
        $eventListeners = array(
            // Resources
            'Imbo\Resource\Index',
            'Imbo\Resource\Status',
            'Imbo\Resource\Stats',
            'Imbo\Resource\GlobalShortUrl',
            'Imbo\Resource\ShortUrls',
            'Imbo\Resource\ShortUrl',
            'Imbo\Resource\User',
            'Imbo\Resource\Images',
            'Imbo\Resource\Image',
            'Imbo\Resource\Metadata',
            'Imbo\Http\Response\ResponseFormatter' => array(
                'formatters' => $formatters,
                'contentNegotiation' => $contentNegotiation,
            ),
            'Imbo\EventListener\DatabaseOperations',
            'Imbo\EventListener\StorageOperations',
            'Imbo\Image\ImagePreparation',
            'Imbo\EventListener\ImageTransformer',
            'Imbo\EventListener\ResponseSender',
            'Imbo\EventListener\ResponseETag',
        );

        foreach ($eventListeners as $listener => $params) {
            if (is_string($params)) {
                $listener = $params;
                $params = array();
            }

            $eventManager->addEventHandler($listener, $listener, $params)
                         ->addCallbacks($listener, $listener::getSubscribedEvents());
        }

        // Event listener initializers
        foreach ($config['eventListenerInitializers'] as $name => $initializer) {
            if (!$initializer) {
                // The initializer has been disabled via config
                continue;
            }

            if (is_string($initializer)) {
                // The initializer has been specified as a string, representing a class name. Create
                // an instance
                $initializer = new $initializer();
            }

            if (!($initializer instanceof InitializerInterface)) {
                throw new InvalidArgumentException('Invalid event listener initializer: ' . $name, 500);
            }

            $eventManager->addInitializer($initializer);
        }

        // Listeners from configuration
        foreach ($config['eventListeners'] as $name => $definition) {
            if (!$definition) {
                // This occurs when a user disables a default event listener
                continue;
            }

            if (is_string($definition)) {
                // Class name
                $eventManager->addEventHandler($name, $definition)
                             ->addCallbacks($name, $definition::getSubscribedEvents());
                continue;
            }

            if (is_callable($definition) && !($definition instanceof ListenerInterface)) {
                // Callable piece of code which is not an implementation of the listener interface
                $definition = $definition();
            }

            if ($definition instanceof ListenerInterface) {
                $eventManager->addEventHandler($name, $definition)
                             ->addCallbacks($name, $definition::getSubscribedEvents());
                continue;
            }

            if (is_array($definition) && !empty($definition['listener'])) {
                $listener = $definition['listener'];
                $params = is_string($listener) && isset($definition['params']) ? $definition['params'] : array();
                $publicKeys = isset($definition['publicKeys']) ? $definition['publicKeys'] : array();

                if (is_callable($listener) && !($listener instanceof ListenerInterface)) {
                    $listener = $listener();
                }

                if (!is_string($listener) && !($listener instanceof ListenerInterface)) {
                    throw new InvalidArgumentException('Invalid event listener definition', 500);
                }

                $eventManager->addEventHandler($name, $listener, $params)
                             ->addCallbacks($name, $listener::getSubscribedEvents(), $publicKeys);
            } else if (is_array($definition) && !empty($definition['callback']) && !empty($definition['events'])) {
                $priority = 0;
                $events = array();
                $publicKeys = array();

                if (isset($definition['priority'])) {
                    $priority = (int) $definition['priority'];
                }

                if (isset($definition['publicKeys'])) {
                    $publicKeys = $definition['publicKeys'];
                }

                foreach ($definition['events'] as $event => $p) {
                    if (is_int($event)) {
                        $event = $p;
                        $p = $priority;
                    }

                    $events[$event] = $p;
                }

                $eventManager->addEventHandler($name, $definition['callback'])
                             ->addCallbacks($name, $events, $publicKeys);
            } else {
                throw new InvalidArgumentException('Invalid event listener definition', 500);
            }
        }

        // Custom resources
        foreach ($config['resources'] as $name => $resource) {
            if (is_callable($resource)) {
                $resource = $resource();
            }

            $eventManager->addEventHandler($name, $resource)
                         ->addCallbacks($name, $resource::getSubscribedEvents());
        }

        try {
            // Route the request
            $router->route($request);

            $eventManager->trigger('route.match');

            // Create the resource
            $routeName = (string) $request->getRoute();

            if (isset($config['resources'][$routeName])) {
                $resource = $config['resources'][$routeName];

                if (is_callable($resource)) {
                    $resource = $resource();
                }

                if (is_string($resource)) {
                    $resource = new $resource();
                }

                if (!$resource instanceof ResourceInterface) {
                    throw new InvalidArgumentException('Invalid resource class for route: ' . $routeName, 500);
                }
            } else {
                $className = 'Imbo\Resource\\' . ucfirst($routeName);
                $resource = new $className();
            }

            // Inform the user agent of which methods are allowed against this resource
            $response->headers->set('Allow', $resource->getAllowedMethods(), false);

            if ($publicKey = $request->getPublicKey()) {
                if (!isset($config['auth'][$publicKey])) {
                    $e = new RuntimeException('Public key not found', 404);
                    $e->setImboErrorCode(Exception::AUTH_UNKNOWN_PUBLIC_KEY);

                    throw $e;
                }

                // Fetch the private key from the config and store it in the request
                $request->setPrivateKey($config['auth'][$publicKey]);
            }

            $methodName = strtolower($request->getMethod());

            // Generate the event name based on the accessed resource and the HTTP method
            $eventName = $routeName . '.' . $methodName;

            if (!$eventManager->hasListenersForEvent($eventName)) {
                throw new RuntimeException('Method not allowed', 405);
            }

            $eventManager->trigger($eventName)
                         ->trigger('response.negotiate');
        } catch (Exception $exception) {
            $negotiated = false;
            $error = Error::createFromException($exception, $request);
            $response->setError($error);

            // If the error is not from the previous attempt at doing content negotiation, force
            // another round since the model has changed into an error model.
            if ($exception->getCode() !== 406) {
                try {
                    $eventManager->trigger('response.negotiate');
                    $negotiated = true;
                } catch (Exception $exception) {
                    // The client does not accept any of the content types. Generate a new error
                    $error = Error::createFromException($exception, $request);
                    $response->setError($error);
                }
            }

            // Try to negotiate in a non-strict manner if the response format still has not been
            // chosen
            if (!$negotiated) {
                $eventManager->trigger('response.negotiate', array(
                    'noStrict' => true,
                ));
            }
        }

        // Send the response
        $eventManager->trigger('response.send');
    }
}
