<?php
// app/Providers/RepositoryServiceProvider.php
namespace App\Providers;

use Illuminate\Support\ServiceProvider;
use App\Contracts\Repositories\FacultadRepository;
use App\Repositories\Eloquent\EloquentFacultadRepository;

class RepositoryServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FacultadRepository::class, EloquentFacultadRepository::class);
    }
}
