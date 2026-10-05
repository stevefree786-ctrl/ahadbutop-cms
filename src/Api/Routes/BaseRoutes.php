<?php

namespace CMS\Api\Routes;

use Slim\App;
use CMS\Api\Controllers\PostsController;
use CMS\Api\Controllers\PagesController;
use CMS\Api\Controllers\MediaController;
use CMS\Api\Controllers\JobQueueController;

/**
 * RETIRED — DO NOT WIRE THIS UP. See the warning below.
 *
 * This file registers post/page/media CRUD under /api/* with NO authentication
 * middleware at all. Calling it would expose unauthenticated write routes:
 * anyone could create, edit or delete content. Nothing references it today —
 * the live route table is built in src/Api/Routes.php (which serves /api/v1
 * through AuthMiddleware + RoleMiddleware) and AdminRoutes.php.
 *
 * It is kept rather than deleted only because this project is not under version
 * control, so removal is unrecoverable. It is not imported by the autoloader's
 * files list, is not required anywhere, and cannot execute unless something
 * explicitly calls BaseRoutes::register().
 *
 * Its controllers are also wrong against the real schema: MediaController
 * references media.file_name / file_path / mime_type, none of which exist
 * (the columns are alt, key and mime). So this would not merely be insecure,
 * it would 500.
 *
 * The equivalent working endpoints already exist under /api/v1 in Routes.php
 * (AdminApiController) and are role-checked. If you need CRUD here, port the
 * route declarations into Routes.php and keep the middleware — do not restore
 * this file as-is.
 *
 * @deprecated Removed by the admin write layer. Delete once under git.
 */
class BaseRoutes
{
    /**
     * Register all API routes
     */
    public static function register(App $app): void
    {
        // Posts API routes
        $app->group('/api/posts', function ($group) {
            $group->get('/', PostsController::class . ':index');           // GET all posts
            $group->get('/{id}', PostsController::class . ':getById');    // GET single post
            $group->post('/', PostsController::class . ':create');        // POST create post
            $group->put('/{id}', PostsController::class . ':update');      // PUT update post
            $group->delete('/{id}', PostsController::class . ':delete');   // DELETE post
        });

        // Pages API routes
        $app->group('/api/pages', function ($group) {
            $group->get('/', PagesController::class . ':index');           // GET all pages
            $group->get('/{id}', PagesController::class . ':getById');    // GET single page
            $group->post('/', PagesController::class . ':create');        // POST create page
            $group->put('/{id}', PagesController::class . ':update');      // PUT update page
            $group->delete('/{id}', PagesController::class . ':delete');   // DELETE page
        });

        // Media API routes
        $app->group('/api/media', function ($group) {
            $group->get('/', MediaController::class . ':index');           // GET all media
            $group->get('/{id}', MediaController::class . ':getById');    // GET single media
            $group->post('/', MediaController::class . ':create');        // POST upload/create media
            $group->put('/{id}', MediaController::class . ':update');      // PUT update media
            $group->delete('/{id}', MediaController::class . ':delete');   // DELETE media
        });

        // Job Queue API routes
        $app->group('/api/jobs', function ($group) {
            $group->get('/pending', JobQueueController::class . ':listPending'); // GET pending jobs
            $group->post('/start', JobQueueController::class . ':startJob');     // POST start job
            $group->post('/{id}/cancel', JobQueueController::class . ':cancel');  // POST cancel job
            $group->get('/{id}/status', JobQueueController::class . ':getStatus'); // GET job status
        });

        // Admin publish endpoint
        $app->post('/api/admin/publish', function ($request, $response) {
            // Implementation would handle bulk publishing of posts/pages
            return $response->withJson([
                'success' => true,
                'message' => 'Publish request processed'
            ]);
        });

        // Settings API endpoint
        $app->group('/api/settings', function ($group) {
            $group->get('/', function ($request, $response) {
                // Implementation would fetch system settings
                return $response->withJson([
                    'success' => true,
                    'settings' => []
                ]);
            });
        });
    }
}
