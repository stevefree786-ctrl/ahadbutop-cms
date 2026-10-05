<?php

namespace CMS\Api\Controllers;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use CMS\Database\Connection;

/**
 * Media Controller - CRUD operations for media files
 */
class MediaController
{
    /**
     * Get all media items (with optional filtering)
     */
    public function index(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $query = "SELECT * FROM media ORDER BY created_at DESC";
        
        // Apply filters if provided
        $queryParams = [];
        if ($request->getQueryParams()) {
            $filters = $request->getQueryParams();
            if (isset($filters['folder'])) {
                $query .= " WHERE folder = ?";
                $queryParams[] = $filters['folder'];
            }
            if (isset($filters['limit'])) {
                $query .= " LIMIT ?";
                $queryParams[] = (int)$filters['limit'];
            }
        }
        
        $stmt = $pdo->getPdo()->prepare($query);
        $stmt->execute($queryParams);
        $media = $stmt->fetchAll();
        
        return new \Slim\Psr7\Response(200, 'application/json', json_encode([
            'success' => true,
            'data' => $media,
            'count' => count($media)
        ]));
    }

    /**
     * Get a single media item by ID
     */
    public function getById(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $args = $request->getAttribute('id');
        
        $stmt = $pdo->query("SELECT * FROM media WHERE id = ?", [$args]);
        $media = $stmt->fetch();
        
        if (!$media) {
            return new \Slim\Psr7\Response(404, 'application/json', json_encode([
                'success' => false,
                'message' => 'Media not found'
            ]));
        }
        
        return new \Slim\Psr7\Response(200, 'application/json', json_encode([
            'success' => true,
            'data' => $media
        ]));
    }

    /**
     * Create a new media item with validation
     */
    public function create(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $data = $request->getParsedBody();
        
        // Basic validation
        if (!isset($data['file_name']) || empty($data['file_name'])) {
            return new \Slim\Psr7\Response(400, 'application/json', json_encode([
                'success' => false,
                'message' => 'File name is required'
            ]));
        }
        
        if (!isset($data['file_path']) || empty($data['file_path'])) {
            return new \Slim\Psr7\Response(400, 'application/json', json_encode([
                'success' => false,
                'message' => 'File path is required'
            ]));
        }
        
        // Prepare data
        $insertData = [
            'file_name' => $data['file_name'],
            'file_path' => $data['file_path'],
            'mime_type' => $data['mime_type'] ?? null,
            'size_bytes' => $data['size_bytes'] ?? 0,
            'width' => $data['width'] ?? null,
            'height' => $data['height'] ?? null,
            'alt_text' => $data['alt_text'] ?? null,
            'title' => $data['title'] ?? null,
            'folder' => $data['folder'] ?? 'uploads',
            'uploaded_by' => $data['uploaded_by'] ?? null
        ];
        
        // Insert media
        $mediaId = $pdo->insert('media', $insertData);
        
        return new \Slim\Psr7\Response(201, 'application/json', json_encode([
            'success' => true,
            'message' => 'Media created successfully',
            'data' => ['id' => $mediaId]
        ]));
    }

    /**
     * Update a media item by ID
     */
    public function update(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $args = $request->getAttribute('id');
        $data = $request->getParsedBody();
        
        // Check if media exists
        $stmt = $pdo->query("SELECT * FROM media WHERE id = ?", [$args]);
        $media = $stmt->fetch();
        
        if (!$media) {
            return new \Slim\Psr7\Response(404, 'application/json', json_encode([
                'success' => false,
                'message' => 'Media not found'
            ]));
        }
        
        // Update fields if provided
        $updateData = [];
        if (isset($data['file_name'])) {
            $updateData['file_name'] = $data['file_name'];
        }
        if (isset($data['alt_text'])) {
            $updateData['alt_text'] = $data['alt_text'];
        }
        if (isset($data['title'])) {
            $updateData['title'] = $data['title'];
        }
        
        if (!empty($updateData)) {
            $pdo->update('media', $updateData, ['id' => $args]);
        }
        
        return new \Slim\Psr7\Response(200, 'application/json', json_encode([
            'success' => true,
            'message' => 'Media updated successfully'
        ]));
    }

    /**
     * Delete a media item by ID
     */
    public function delete(ServerRequestInterface $request): ResponseInterface
    {
        $pdo = new Connection(require __DIR__ . '/../../config/database.php');
        $args = $request->getAttribute('id');
        
        // Check if media exists
        $stmt = $pdo->query("SELECT * FROM media WHERE id = ?", [$args]);
        $media = $stmt->fetch();
        
        if (!$media) {
            return new \Slim\Psr7\Response(404, 'application/json', json_encode([
                'success' => false,
                'message' => 'Media not found'
            ]));
        }
        
        // Delete media record
        $pdo->delete('media', ['id' => $args]);
        
        return new \Slim\Psr7\Response(200, 'application/json', json_encode([
            'success' => true,
            'message' => 'Media deleted successfully'
        ]));
    }
}
