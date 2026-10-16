<?php

namespace Elcreator\aMount\Http\Controllers;

use Elcreator\aMount\Services\AmountException;
use Elcreator\aMount\Services\Rules;
use Elcreator\aMount\Support\Paths;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * The site's own folders, as far as the signed-in manager may see them.
 *
 * Everything is answered by {@see \Elcreator\aMount\Support\Scope}, the check the
 * worker repeats per file, so the picker never offers what a run would refuse.
 */
class LocalController extends Controller
{
    private const MAX_ITEMS = 500;

    /**
     * Sub-folders of `path` (and with `files=1` the files directly inside it).
     * An empty path starts at the top of the manager's file root.
     */
    public function folders(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $path = Paths::clean($this->text($request, 'path'));

            if ($path === null) {
                throw AmountException::invalid('error_path_invalid');
            }

            $scope = $this->actor()->scope();

            // A manager whose file root is a sub-folder starts there, not at the site root.
            if ($path === '' && $scope->denyDirectory(Paths::absolute('')) !== null) {
                $path = (string) Paths::relative($scope->root());
            }

            $absolute = Paths::absolute($path);
            $allowed = $scope->denyDirectory($absolute) === null;
            $folders = [];

            foreach (array_slice($scope->folders($absolute), 0, self::MAX_ITEMS) as $folder) {
                $folders[] = [
                    'name' => basename($folder),
                    'path' => $folder,
                    'hasChildren' => $this->hasChildren(Paths::absolute($folder)),
                ];
            }

            $payload = [
                'path' => $path,
                'parent' => $path === '' ? null : (str_contains($path, '/') ? dirname($path) : ''),
                'selectable' => $allowed && $path !== '',
                'folders' => $folders,
            ];

            if ($allowed && $this->flag($request, 'files')) {
                $payload['files'] = $this->files($scope, $absolute);
            }

            return $payload;
        });
    }

    /**
     * Can this folder or file be offloaded by the signed-in manager, and if not, why?
     * `reason` is the {@see \Elcreator\aMount\Support\Scope} code, or `missing`/`invalid`.
     */
    public function check(Request $request): JsonResponse
    {
        return $this->respond(function () use ($request) {
            $path = Paths::clean($this->text($request, 'path'));

            if ($path === null || $path === '') {
                return $this->verdict('', false, 'invalid');
            }

            $scope = $this->actor()->scope();
            $absolute = Paths::absolute($path);

            // The layers that need no filesystem probing first, so the answer for a
            // place outside the manager's reach never says whether it exists.
            $early = $scope->denyFile($absolute);

            if ($early !== null) {
                return $this->verdict($path, false, $early);
            }

            if (is_dir($absolute)) {
                $reason = $scope->denyDirectory($absolute);
                $type = 'dir';
            } elseif (is_file($absolute)) {
                $reason = $scope->denyFile($absolute);
                $type = 'file';
            } else {
                return $this->verdict($path, false, 'missing');
            }

            return $this->verdict($path, $reason === null, $reason, $type, $scope->canModify($absolute), Rules::publishable($path));
        });
    }

    /** @return array<string, mixed> */
    private function verdict(string $path, bool $offloadable, ?string $reason, ?string $type = null, bool $canModify = false, bool $publishable = false): array
    {
        return [
            'path' => $path,
            'type' => $type,
            'offloadable' => $offloadable,
            'reason' => $reason,
            'can_modify' => $offloadable && $canModify,
            'publishable' => $offloadable && $publishable,
        ];
    }

    /** Best effort: any sub-directory at all, without asking the ACL for each one. */
    private function hasChildren(string $absolute): bool
    {
        $handle = @opendir($absolute);

        if ($handle === false) {
            return false;
        }

        $seen = 0;

        while (($entry = readdir($handle)) !== false && $seen++ < 200) {
            if ($entry !== '.' && $entry !== '..' && is_dir($absolute . '/' . $entry) && !is_link($absolute . '/' . $entry)) {
                closedir($handle);

                return true;
            }
        }

        closedir($handle);

        return false;
    }

    /** @return array<int, array{name:string, path:string, size:int}> */
    private function files($scope, string $absolute): array
    {
        $files = [];

        foreach (@scandir($absolute) ?: [] as $name) {
            $file = $absolute . '/' . $name;

            if ($name === '.' || $name === '..' || !is_file($file) || $scope->denyFile($file) !== null) {
                continue;
            }

            $files[] = ['name' => $name, 'path' => (string) Paths::relative($file), 'size' => (int) @filesize($file)];

            if (count($files) >= self::MAX_ITEMS) {
                break;
            }
        }

        return $files;
    }
}
