<?php

namespace App\Http\Controllers;

use App\Http\Resources\ContactResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class ProfileController extends Controller
{
    /**
     * Avatars are cropped to a centred square of this size.
     */
    private const AVATAR_SIZE = 512;

    public function update(Request $request): ContactResource
    {
        $validated = $request->validate([
            'name' => ['required', 'string', 'max:64'],
        ]);

        $request->user()->update(['name' => trim($validated['name'])]);

        return new ContactResource($request->user());
    }

    public function updateAvatar(Request $request): ContactResource
    {
        $request->validate([
            'avatar' => ['required', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:10240'],
        ]);

        $user = $request->user();
        $jpeg = $this->squareJpeg((string) file_get_contents($request->file('avatar')->getRealPath()));

        $path = 'avatars/'.$user->id.'-'.Str::random(16).'.jpg';
        Storage::disk('local')->put($path, $jpeg);

        if ($user->avatar_path !== null) {
            Storage::disk('local')->delete($user->avatar_path);
        }

        $user->forceFill(['avatar_path' => $path])->save();

        return new ContactResource($user);
    }

    public function destroyAvatar(Request $request): ContactResource
    {
        $user = $request->user();

        if ($user->avatar_path !== null) {
            Storage::disk('local')->delete($user->avatar_path);
            $user->forceFill(['avatar_path' => null])->save();
        }

        return new ContactResource($user);
    }

    /**
     * Any signed-in user may see avatars (they appear in search results).
     */
    public function avatar(User $user): BinaryFileResponse
    {
        $disk = Storage::disk('local');
        abort_if($user->avatar_path === null || ! $disk->exists($user->avatar_path), 404);

        return response()->file($disk->path($user->avatar_path), [
            'Content-Type' => 'image/jpeg',
            'X-Content-Type-Options' => 'nosniff',
            'Cache-Control' => 'private, max-age=31536000, immutable',
        ]);
    }

    /**
     * Re-encode the upload as a centred square JPEG; this also strips EXIF/GPS metadata.
     */
    private function squareJpeg(string $contents): string
    {
        $source = @imagecreatefromstring($contents);

        if ($source === false) {
            throw ValidationException::withMessages(['avatar' => 'Rasmni o\'qib bo\'lmadi.']);
        }

        $width = imagesx($source);
        $height = imagesy($source);
        $side = min($width, $height);
        $target = min(self::AVATAR_SIZE, $side);

        $canvas = imagecreatetruecolor($target, $target);
        imagefill($canvas, 0, 0, imagecolorallocate($canvas, 255, 255, 255));
        imagecopyresampled($canvas, $source, 0, 0, intdiv($width - $side, 2), intdiv($height - $side, 2), $target, $target, $side, $side);

        ob_start();
        imagejpeg($canvas, null, 88);

        return (string) ob_get_clean();
    }
}
