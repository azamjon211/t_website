<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\ChatController;
use App\Http\Controllers\GifController;
use App\Http\Controllers\MessageController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\StoryController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function () {
    Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:login');
    Route::get('/register', [AuthController::class, 'showRegister'])->name('register');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:register');
});

Route::middleware('auth')->group(function () {
    Route::get('/', [ChatController::class, 'index'])->name('chat');
    Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

    Route::get('/media/{message}', [MessageController::class, 'media'])->name('media.show');
    Route::get('/avatars/{user}', [ProfileController::class, 'avatar'])->name('avatars.show');
    Route::get('/stories/{story}/media', [StoryController::class, 'media'])->name('stories.media');

    Route::prefix('api')->name('api.')->group(function () {
        Route::patch('/profile', [ProfileController::class, 'update'])->name('profile.update');
        Route::post('/profile/avatar', [ProfileController::class, 'updateAvatar'])->name('profile.avatar.update');
        Route::delete('/profile/avatar', [ProfileController::class, 'destroyAvatar'])->name('profile.avatar.destroy');

        Route::get('/stories', [StoryController::class, 'index'])->name('stories.index');
        Route::post('/stories', [StoryController::class, 'store'])->middleware('throttle:messages')->name('stories.store');
        Route::post('/stories/{story}/view', [StoryController::class, 'markViewed'])->name('stories.view');
        Route::get('/stories/{story}/viewers', [StoryController::class, 'viewers'])->name('stories.viewers');
        Route::delete('/stories/{story}', [StoryController::class, 'destroy'])->name('stories.destroy');

        Route::get('/chats', [ChatController::class, 'chats'])->name('chats.index');
        Route::get('/sync', [ChatController::class, 'sync'])->name('sync');
        Route::get('/users/search', [ChatController::class, 'search'])->name('users.search');
        Route::get('/gifs', [GifController::class, 'search'])->middleware('throttle:60,1')->name('gifs.search');
        Route::get('/users/{user:username}', [ChatController::class, 'contact'])->name('users.show');

        Route::get('/chats/{user}/messages', [MessageController::class, 'index'])->name('messages.index');
        Route::post('/chats/{user}/messages', [MessageController::class, 'store'])->middleware('throttle:messages')->name('messages.store');
        Route::post('/chats/{user}/read', [MessageController::class, 'markRead'])->name('messages.read');
        Route::post('/chats/{user}/typing', [ChatController::class, 'typing'])->name('chats.typing');

        Route::patch('/messages/{message}', [MessageController::class, 'update'])->name('messages.update');
        Route::delete('/messages/{message}', [MessageController::class, 'destroy'])->name('messages.destroy');
    });
});
