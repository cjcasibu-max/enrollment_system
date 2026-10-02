<?php
/**
 * Document Storage Configuration & Path Resolution
 *
 * Centralizes resolution of secure document storage locations.
 * Prioritizes storage outside the public web root (DocumentRoot) to ensure
 * uploaded student documents are never directly accessible via HTTP requests,
 * even if web server configuration (.htaccess / AllowOverride) fails.
 */

/**
 * Returns an ordered array of candidate root directories for student document storage.
 *
 * Resolution Priority:
 * 1. Environment variable PRIVATE_UPLOADS_PATH (if defined by system administrator)
 * 2. Dedicated directory outside the web root (e.g. C:\xampp\private_uploads\student_documents)
 * 3. In-repository fallback directory: private_uploads/student_documents (protected by .htaccess)
 * 4. Legacy in-repository directory: uploads/student_documents
 *
 * @return array<string> Ordered list of candidate directory paths
 */
function getDocumentStorageRoots(): array
{
    $candidateRoots = [];

    // 1. Environment variable override for staging/production deployments
    $envStorage = getenv('PRIVATE_UPLOADS_PATH');
    if ($envStorage !== false && is_string($envStorage) && trim($envStorage) !== '') {
        $candidateRoots[] = rtrim(trim($envStorage), '/\\') . DIRECTORY_SEPARATOR . 'student_documents';
    }

    // 2. Directory outside web root (sibling to htdocs / parent of project)
    $projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $parentOfProject = dirname($projectRoot); // e.g. C:\xampp\htdocs
    $outsideParent = dirname($parentOfProject); // e.g. C:\xampp
    $outsideWebRoot = $outsideParent . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . 'student_documents';

    $webRoot = !empty($_SERVER['DOCUMENT_ROOT'])
        ? (realpath($_SERVER['DOCUMENT_ROOT']) ?: $_SERVER['DOCUMENT_ROOT'])
        : $parentOfProject;

    $isInsideProject = str_starts_with($outsideWebRoot, $projectRoot . DIRECTORY_SEPARATOR);
    $isInsideWebRoot = str_starts_with($outsideWebRoot, $webRoot . DIRECTORY_SEPARATOR);

    if (!$isInsideProject && !$isInsideWebRoot && is_dir($outsideParent) && is_writable($outsideParent)) {
        $candidateRoots[] = $outsideWebRoot;
    }

    // 3. In-repo fallback: <projectRoot>/private_uploads/student_documents
    $candidateRoots[] = $projectRoot . DIRECTORY_SEPARATOR . 'private_uploads' . DIRECTORY_SEPARATOR . 'student_documents';

    // 4. In-repo legacy fallback: <projectRoot>/uploads/student_documents
    $candidateRoots[] = $projectRoot . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'student_documents';

    return $candidateRoots;
}
