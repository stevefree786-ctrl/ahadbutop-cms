<?php

namespace CMS\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use CMS\Database\Connection;

/**
 * Pages Controller - CRUD operations for pages
 */
class PagesController
{
    /**
     * Get all pages (with optional filtering)
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $query = "SELECT * FROM pages ORDER BY created_at DESC";
        
        // Apply filters if provided
        $queryParams = [];
        if ($request->getQueryParams()) {
            $filters = $request->getQueryParams();
            if (isset($filters['status'])) {
                $query .= " WHERE status = ?";
                $queryParams[] = $filters['status'];
            }
            if (isset($filters['limit'])) {
                $query .= " LIMIT ?";
                $queryParams[] = (int)$filters['limit'];
            }
        }
        
        $stmt = $pdo->getPdo()->prepare($query);
        $stmt->execute($queryParams);
        $pages = $stmt->fetchAll();
        
        return new \Slim\Psr7\Response(200, 'application/json', json_encode([
            'success' => true,
            'data' => $pages,
            'count' => count($pages)
        ]));
    }

    /**
     * Get a single page by ID
     */
    public function getById(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $args = $request->getAttribute('id');
        
        $stmt = $pdo->query("SELECT * FROM pages WHERE id = ?", [$args]);
        $page = $stmt->fetch();
        
        if (!$page) {
            return new \Slim\Psr7\Response(404, 'application/json', json_encode([
                'success' => false,
                'message' => 'Page not found'
            ]));
        }
        
        return new \Slim\Psr7\Response(200, 'application/json', json_encode([
            'success' => true,
            'data' => $page
        ]));
    }

    /**
     * Create a new page with validation
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $data = $request->getParsedBody();
        
        // Basic validation
        if (!isset($data['title']) || empty($data['title'])) {
            return new \Slim\Psr7\Response(400, 'application/json', json_encode([
                'success' => false,
                'message' => 'Title is required'
            ]));
        }
        
        if (!isset($data['body']) || empty($data['body'])) {
            return new \Slim\Psr7\Response(400, 'application/json', json_encode([
                'success' => false,
                'message' => 'Body is required'
            ]));
        }
        
        // Prepare data
        $insertData = [
            'slug' => $data['slug'] ?? $this->generateSlug($data['title']),
            'title' => $data['title'],
            'body' => $data['body'],
            'template' => $data['template'] ?? 'default',
            'parent_id' => $data['parent_id'] ?? null,
            'status' => $data['status'] ?? 'draft',
            'published_at' => $data['status'] === 'published' ? date('Y-m-d H:i:s') : null,
            'author_id' => $data['author_id'] ?? null
        ];
        
        // Insert page
        $pageId = $pdo->insert('pages', $insertData);
        
        // Enqueue SEO processing job if page is being published
        if ($insertData['status'] === 'published') {
            $this->enqueueSeoJob($pageId, 'page', $insertData);
        }
        
        return new \Slim\Psr7\Response(201, 'application/json', json_encode([
            'success' => true,
            'message' => 'Page created successfully',
            'data' => ['id' => $pageId]
        ]));
    }

    /**
     * Update a page by ID
     */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $args = $request->getAttribute('id');
        $data = $request->getParsedBody();
        
        // Check if page exists
        $stmt = $pdo->query("SELECT * FROM pages WHERE id = ?", [$args]);
        $page = $stmt->fetch();
        
        if (!$page) {
            return new \Slim\Psr7\Response(404, 'application/json', json_encode([
                'success' => false,
                'message' => 'Page not found'
            ]));
        }
        
        // Update fields if provided
        $updateData = [];
        if (isset($data['title'])) {
            $updateData['title'] = $data['title'];
            $updateData['slug'] = $data['slug'] ?? $this->generateSlug($data['title']);
        }
        if (isset($data['body'])) {
            $updateData['body'] = $data['body'];
        }
        if (isset($data['status'])) {
            $updateData['status'] = $data['status'];
            if ($data['status'] === 'published' && !$page['published_at']) {
                $updateData['published_at'] = date('Y-m-d H:i:s');
                // Enqueue SEO job for newly published page
                $this->enqueueSeoJob($args, 'page', $updateData);
            }
        }
        
        if (!empty($updateData)) {
            $pdo->update('pages', $updateData, ['id' => $args]);
        }
        
        return new \Slim\Psr7\Response(200, 'application/json', json_encode([
            'success' => true,
            'message' => 'Page updated successfully'
        ]));
    }

    /**
     * Delete a page by ID
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $args = $request->getAttribute('id');
        
        // Check if page exists
        $stmt = $pdo->query("SELECT * FROM pages WHERE id = ?", [$args]);
        $page = $stmt->fetch();
        
        if (!$page) {
            return new \Slim\Psr7\Response(404, 'application/json', json_encode([
                'success' => false,
                'message' => 'Page not found'
            ]));
        }
        
        // Delete page (cascade will handle related records)
        $pdo->delete('pages', ['id' => $args]);
        
        return new \Slim\Psr7\Response(200, 'application/json', json_encode([
            'success' => true,
            'message' => 'Page deleted successfully'
        ]));
    }

    /**
     * Generate slug from title
     */
    private function generateSlug(string $title): string
    {
        $slug = strtolower(preg_replace('/[^a-z0-9]+/', '-', trim($title)));
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        
        // Ensure uniqueness
        $count = 1;
        $originalSlug = $slug;
        while ($pdo->query("SELECT COUNT(*) FROM pages WHERE slug = ?", [$slug])->fetch()['COUNT(*)'] > 0) {
            $slug = $originalSlug . '-' . $count++;
        }
        
        return $slug;
    }

    /**
     * Enqueue SEO processing job
     */
    private function enqueueSeoJob(int $id, string $type, array $data): void
    {
        // Implementation would create a job in the jobs table
        $jobData = [
            'job_name' => 'seo_processing',
            'payload' => json_encode([
                'id' => $id,
                'type' => $type,
                'data' => $data
            ])
        ];
        
        // In real implementation: $pdo->insert('jobs', $jobData);
        error_log("SEO job enqueued for $type with ID: $id");
    }
}
