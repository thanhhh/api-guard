<?php

namespace Chrisbjr\ApiGuard;

use Controller;
use Route;
use Request;
use Config;
use Log;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Input;
use EllipseSynergie\ApiResponse\Laravel\Response;
use League\Fractal\Manager;

class ApiGuardController extends Controller
{

    /**
     * @var array
     */
    protected $apiMethods;

    /**
     * @var ApiKey|null|static
     */
    public $apiKey = null;

    /**
     * @var \EllipseSynergie\ApiResponse\Laravel\Response
     */
    public $response;

    /**
     * @var \League\Fractal\Manager
     */
    public $manager;

    public function __construct()
    {
        // Let's instantiate the response class first
        $this->manager = new Manager;

        $this->manager->parseIncludes(Input::get(Config::get('api-guard::includeKeyword', 'include'), array()));

        $this->response = new Response($this->manager);

        $this->beforeFilter(function () {

            // api-guard might not be the only before filter on the controller
            // loop through any before filters and pull out $apiMethods in the controller
            $beforeFilters = $this->getBeforeFilters();
            foreach ($beforeFilters as $filter) {
                if (!empty($filter['options']['apiMethods'])) {
                    $apiMethods = $filter['options']['apiMethods'];
                }
            }

            // This is the actual request object used
            $request = Route::getCurrentRequest();

            // Let's get the method
            Str::parseCallback(Route::currentRouteAction(), null);
            $routeArray = Str::parseCallback(Route::currentRouteAction(), null);

            if (last($routeArray) == null) {
                // There is no method?
                return $this->response->errorMethodNotAllowed();
            }

            $method = last($routeArray);

            // We should check if key authentication is enabled for this method
            $keyAuthentication = true;
            if (isset($apiMethods[$method]['keyAuthentication']) && $apiMethods[$method]['keyAuthentication'] === false) {
                $keyAuthentication = false;
            }

            if ($keyAuthentication === true) {

                $key = $request->header(Config::get('api-guard::keyName'));

                if (empty($key)) {
                    // Try getting the key from elsewhere
                    $key = Input::get(Config::get('api-guard::keyName'));
                }

                if (empty($key)) {
                    // It's still empty!
                    return $this->response->errorUnauthorized();
                }

                $this->apiKey = ApiKey::where('key', '=', $key)->first();

                if (!isset($this->apiKey->id)) {
                    // ApiKey not found
                    return $this->response->errorUnauthorized();
                }

                // API key exists
                // Check level of API
                if (!empty($apiMethods[$method]['level'])) {
                    if ($this->apiKey->level < $apiMethods[$method]['level']) {
                        return $this->response->errorForbidden();
                    }
                }
            }

            // Then check the limits of this method
            if (!empty($apiMethods[$method]['limits'])) {

                if (Config::get('api-guard::logging') === false) {
                    Log::warning("[Chrisbjr/ApiGuard] You specified a limit in the $method method but API logging needs to be enabled in the configuration for this to work.");
                }

                $limits = $apiMethods[$method]['limits'];

                // We get key level limits first
                if ($this->apiKey != null && !empty($limits['key'])) {

                    Log::info("key limits found");

                    $keyLimit = (!empty($limits['key']['limit'])) ? $limits['key']['limit'] : 0;
                    if ($keyLimit == 0 || is_integer($keyLimit) == false) {
                        Log::warning("[Chrisbjr/ApiGuard] You defined a key limit to the " . Route::currentRouteAction() . " route but you did not set a valid number for the limit variable.");
                    } else {
                        if (!$this->apiKey->ignore_limits) {
                            // This means the apikey is not ignoring the limits

                            $keyIncrement = (!empty($limits['key']['increment'])) ? $limits['key']['increment'] : Config::get('api-guard::keyLimitIncrement');

                            $keyIncrementTime = strtotime('-' . $keyIncrement);

                            if ($keyIncrementTime == false) {
                                Log::warning("[Chrisbjr/ApiGuard] You have specified an invalid key increment time. This value can be any value accepted by PHP's strtotime() method");
                            } else {
                                // Count the number of requests for this method using this api key
                                $apiLogCount = ApiLog::where('api_key_id', '=', $this->apiKey->id)
                                    ->where('route', '=', Route::currentRouteAction())
                                    ->where('method', '=', $request->getMethod())
                                    ->where('created_at', '>=', date('Y-m-d H:i:s', $keyIncrementTime))
                                    ->where('created_at', '<=', date('Y-m-d H:i:s'))
                                    ->count();

                                if ($apiLogCount >= $keyLimit) {
                                    Log::warning("[Chrisbjr/ApiGuard] The API key ID#{$this->apiKey->id} has reached the limit of {$keyLimit} in the following route: " . Route::currentRouteAction());
                                    return $this->response->errorUnwillingToProcess('You have reached the limit for using this API.');
                                }
                            }
                        }
                    }
                }

                // Then the overall method limits
                if (!empty($limits['method'])) {
                    $methodLimit = (!empty($limits['method']['limit'])) ? $limits['method']['limit'] : 0;
                    if ($methodLimit == 0 || is_integer($methodLimit) == false) {
                        Log::warning("[Chrisbjr/ApiGuard] You defined a method limit to the " . Route::currentRouteAction() . " route but you did not set a valid number for the limit variable.");
                    } else {
                        if ($this->apiKey != null && $this->apiKey->ignore_limits == true) {
                            // then we skip this
                        } else {

                            $methodIncrement = (!empty($limits['method']['increment'])) ? $limits['method']['increment'] : Config::get('api-guard::keyLimitIncrement');

                            $methodIncrementTime = strtotime('-' . $methodIncrement);

                            if ($methodIncrementTime == false) {
                                Log::warning("[Chrisbjr/ApiGuard] You have specified an invalid method increment time. This value can be any value accepted by PHP's strtotime() method");
                            } else {
                                // Count the number of requests for this method
                                $apiLogCount = ApiLog::where('route', '=', Route::currentRouteAction())
                                    ->where('method', '=', $request->getMethod())
                                    ->where('created_at', '>=', date('Y-m-d H:i:s', $methodIncrementTime))
                                    ->where('created_at', '<=', date('Y-m-d H:i:s'))
                                    ->count();

                                if ($apiLogCount >= $methodLimit) {
                                    Log::warning("[Chrisbjr/ApiGuard] The API has reached the method limit of {$methodLimit} in the following route: " . Route::currentRouteAction());
                                    return $this->response->setStatusCode(429)->withError('The limit for using this API method has been reached', 'GEN-LIMIT-REACHED');
                                }
                            }
                        }
                    }
                }
            }
            // End of cheking limits

            if (Config::get('api-guard::logging') && $keyAuthentication == true) {
                // Log this API request
                $apiLog = new ApiLog;
                $apiLog->api_key_id = $this->apiKey->id;
                $apiLog->route = Route::currentRouteAction();
                $apiLog->method = $request->getMethod();
                $apiLog->params = http_build_query(Input::all());
                $apiLog->ip_address = $request->getClientIp();
                $apiLog->save();
            }
        }, ['apiMethods' => $this->apiMethods]);
    }

}
