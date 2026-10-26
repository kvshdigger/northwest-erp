<?php

namespace App\Providers\Filament;

use App\Http\Middleware\ApplyBrandSettings;
use App\Http\Middleware\SetLocale;
use BezhanSalleh\FilamentShield\FilamentShieldPlugin;
use Filament\Actions\Action;
use Filament\Auth\MultiFactor\App\AppAuthentication;
use Filament\Http\Middleware\Authenticate;
use Filament\Http\Middleware\DisableBladeIconComponents;
use Filament\Http\Middleware\DispatchServingFilamentEvent;
use Filament\Navigation\NavigationGroup as FilamentNavigationGroup;
use Filament\Panel;
use Filament\PanelProvider;
use Filament\View\PanelsRenderHook;
use Illuminate\Support\HtmlString;
use Filament\Support\Colors\Color;
use Filament\Support\Enums\Width;
use Filament\Tables\Table;
use Illuminate\Cookie\Middleware\AddQueuedCookiesToResponse;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\View\Middleware\ShareErrorsFromSession;
use LaraZeus\SpatieTranslatable\SpatieTranslatablePlugin;
use Webkul\Support\Enums\NavigationGroup;
use Webkul\Support\Filament\Pages\Profile;
use Webkul\Support\GlobalSearchProvider;

class AdminPanelProvider extends PanelProvider
{
    public function panel(Panel $panel): Panel
    {
        set_time_limit(300);

        return $panel
            ->default()
            ->id('admin')
            ->path('admin')
            ->theme(asset('css/filament/admin/theme.css').'?v='.filemtime(public_path('css/filament/admin/theme.css')))
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString(<<<'HTML'
<style>
/* Northwest ERP login — clean split layout */
.fi-simple-layout {
    display: grid !important;
    grid-template-columns: minmax(0, 1fr) minmax(420px, 0.9fr) !important;
    min-height: 100vh !important;
    padding: 0 !important;
    gap: 0 !important;
    background: #f6f8fc !important;
}
.fi-simple-layout::before {
    content: "NORTHWEST ERP\\A\\A A clearer view of your business.\\A\\A One workspace for your operations, finances, and day-to-day decisions.";
    white-space: pre-line;
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 100vh;
    padding: clamp(2.5rem, 6vw, 6rem);
    color: #fff;
    font-size: clamp(1.05rem, 1.5vw, 1.3rem);
    line-height: 1.9;
    font-weight: 400;
    background: radial-gradient(ellipse at 20% 20%, rgba(71,112,184,.28), transparent 40%), linear-gradient(145deg, #101b32 0%, #1a3156 55%, #0d172a 100%);
}
.fi-simple-layout::after {
    content: "";
    position: absolute;
    inset: 0 auto 0 0;
    width: 50%;
    pointer-events: none;
    opacity: .12;
    background-image: linear-gradient(rgba(255,255,255,.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.1) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: linear-gradient(135deg, #000, transparent 85%);
}
.fi-simple-main-ctn {
    display: flex !important;
    align-items: center !important;
    justify-content: center !important;
    width: 100% !important;
    max-width: none !important;
    min-height: 100vh !important;
    padding: clamp(1.5rem, 4vw, 4rem) !important;
    background: #f6f8fc !important;
}
.fi-simple-main {
    display: flex !important;
    flex-direction: column !important;
    justify-content: center !important;
    align-items: stretch !important;
    width: 100% !important;
    max-width: 460px !important;
    min-height: auto !important;
    margin: 0 auto !important;
    padding: 0 !important;
    background: transparent !important;
}
.fi-simple-header {
    margin-bottom: 1.5rem !important;
}
.fi-simple-header .fi-logo {
    max-height: 2.5rem !important;
    width: auto !important;
}
.fi-simple-page {
    width: 100% !important;
    max-width: 460px !important;
    padding: clamp(1.5rem, 3vw, 2.25rem) !important;
    border: 1px solid #e1e6ef !important;
    border-radius: 1.1rem !important;
    background: #fff !important;
    box-shadow: 0 18px 50px rgba(20, 35, 60, .08) !important;
}
.fi-simple-page .fi-header-heading {
    color: #111d35 !important;
    font-size: 1.7rem !important;
    line-height: 1.25 !important;
    font-weight: 650 !important;
    letter-spacing: -.03em !important;
}
.fi-simple-page .fi-header-subheading {
    color: #68758b !important;
    font-size: .95rem !important;
    line-height: 1.6 !important;
}
.fi-simple-page label,
.fi-simple-page .fi-fo-field-wrp-label,
.fi-simple-page .fi-checkbox-label {
    color: #344054 !important;
}
.fi-simple-page .fi-input-wrp {
    min-height: 2.9rem !important;
    border: 1px solid #cbd5e1 !important;
    border-radius: .65rem !important;
    background: #fff !important;
    box-shadow: none !important;
}
.fi-simple-page .fi-input-wrp:focus-within {
    border-color: #315b99 !important;
    box-shadow: 0 0 0 3px rgba(49, 91, 153, .13) !important;
}
.fi-simple-page input,
.fi-simple-page input[type="email"],
.fi-simple-page input[type="password"] {
    color: #172033 !important;
    background: transparent !important;
    -webkit-text-fill-color: #172033 !important;
    opacity: 1 !important;
}
.fi-simple-page input::placeholder {
    color: #98a2b3 !important;
    -webkit-text-fill-color: #98a2b3 !important;
}
.fi-simple-page .fi-btn {
    min-height: 2.9rem !important;
    border-radius: .65rem !important;
    font-weight: 600 !important;
    box-shadow: none !important;
}
@media (max-width: 900px) {
    .fi-simple-layout {
        grid-template-columns: minmax(0, 1fr) !important;
    }
    .fi-simple-layout::before {
        min-height: auto;
        padding: 1.75rem 1.5rem;
        font-size: 1rem;
        line-height: 1.55;
        content: "NORTHWEST ERP\\A A clearer view of your business.";
    }
    .fi-simple-layout::after { display: none; }
    .fi-simple-main-ctn {
        min-height: auto !important;
        padding: 2rem 1.25rem 3rem !important;
    }
}
@media (max-width: 480px) {
    .fi-simple-layout::before { padding: 1.4rem 1.25rem; }
    .fi-simple-main-ctn { padding: 1.25rem 1rem 2rem !important; }
    .fi-simple-page { padding: 1.25rem !important; border-radius: .9rem !important; }
}
</style>
HTML
))
            ->login()
            ->favicon(asset('images/favicon.ico'))
            ->brandLogo(asset('images/logo.svg'))
            ->brandLogoHeight('2rem')
            ->passwordReset()
            ->emailVerification()
            ->profile()
            ->colors([
                'primary' => Color::Blue,
            ])
            ->unsavedChangesAlerts()
            ->topNavigation()
            ->maxContentWidth(Width::Full)
            ->simplePageMaxContentWidth(Width::Small)
            ->bootUsing(fn (): mixed => Table::configureUsing(fn (Table $table): Table => $table
                ->loadingSkeleton()
                ->filtersFormWidth(Width::Large)))
            ->databaseNotifications()
            ->databaseNotificationsPolling('30s')
            ->userMenuItems([
                'profile' => Action::make('profile')
                    ->label(fn () => Auth::user()?->name)
                    ->url(fn (): string => Profile::getUrl()),
            ])
            ->navigationGroups(
                collect(NavigationGroup::cases())->mapWithKeys(
                    fn (NavigationGroup $case) => [
                        $case->name => FilamentNavigationGroup::make()
                            ->label(fn () => $case->getLabel())
                            ->icon(fn () => $case->getIcon()),
                    ]
                )->all()
            )
            ->plugins([
                FilamentShieldPlugin::make()
                    ->gridColumns([
                        'default' => 1,
                        'sm'      => 1,
                        'lg'      => 2,
                        'xl'      => 3,
                    ])
                    ->sectionColumnSpan(1)
                    ->checkboxListColumns([
                        'default' => 1,
                        'sm'      => 1,
                        'lg'      => 2,
                        'xl'      => 3,
                    ])
                    ->resourceCheckboxListColumns([
                        'default' => 1,
                        'sm'      => 2,
                    ]),
                SpatieTranslatablePlugin::make()
                    ->defaultLocales(array_keys(config('app.supported_locales'))),
            ])
            ->globalSearch(provider: GlobalSearchProvider::class)
            ->middleware([
                EncryptCookies::class,
                AddQueuedCookiesToResponse::class,
                StartSession::class,
                AuthenticateSession::class,
                ShareErrorsFromSession::class,
                PreventRequestForgery::class,
                SubstituteBindings::class,
                DisableBladeIconComponents::class,
                DispatchServingFilamentEvent::class,
                SetLocale::class,
                ApplyBrandSettings::class,
            ])
            ->authMiddleware([
                Authenticate::class,
            ])
            ->multiFactorAuthentication([
                AppAuthentication::make()
                    ->recoverable(),
            ]);
    }
}
