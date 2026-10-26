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
            ->renderHook(PanelsRenderHook::HEAD_END, fn (): HtmlString => new HtmlString('<style>
.fi-simple-layout{position:relative!important;display:grid!important;grid-template-columns:minmax(0,1.08fr) minmax(420px,.92fr)!important;align-items:stretch!important;gap:0!important;min-height:100vh!important;padding:0!important;background:#f7f8fb!important}
.fi-simple-layout::before{content:"NORTHWEST ERP\A\A A clearer view of your business.\A\A One workspace for your operations,\A finances, and day-to-day decisions.";white-space:pre-line;display:flex;flex-direction:column;justify-content:center;min-height:100vh;padding:clamp(3rem,7vw,7rem);color:#fff;font-size:clamp(1.1rem,1.6vw,1.45rem);line-height:1.8;font-weight:400;letter-spacing:.01em;background:radial-gradient(ellipse at 18% 20%,rgba(67,111,190,.35),transparent 38%),radial-gradient(ellipse at 88% 82%,rgba(28,72,137,.55),transparent 42%),linear-gradient(145deg,#101b32 0%,#172c50 55%,#0c1629 100%)}
.fi-simple-layout::after{content:"";position:absolute;inset:0 auto 0 0;width:54.5%;pointer-events:none;opacity:.18;background-image:linear-gradient(rgba(255,255,255,.08) 1px,transparent 1px),linear-gradient(90deg,rgba(255,255,255,.08) 1px,transparent 1px);background-size:54px 54px;mask-image:linear-gradient(135deg,#000,transparent 82%)}
.fi-simple-main-ctn{width:100%!important;max-width:none!important;min-height:100vh;display:flex;align-items:center;justify-content:center;background:#f7f8fb}
.fi-simple-main{width:100%!important;max-width:none!important;min-height:100vh;display:flex;flex-direction:column;justify-content:center;align-items:center;margin:0!important;padding:clamp(2rem,5vw,5rem)!important;background:#f7f8fb!important}
.fi-simple-main-ctn>.fi-simple-main{width:100%!important;max-width:none!important}
.fi-simple-page{width:100%;max-width:440px;border:1px solid #e7eaf0!important;border-radius:1.25rem!important;background:#fff!important;padding:clamp(1.5rem,3vw,2.25rem)!important;box-shadow:0 22px 60px rgba(17,29,53,.07)!important}
.fi-simple-page .fi-header-heading{color:#111d35!important;font-size:1.65rem!important;font-weight:650!important;letter-spacing:-.035em}
.fi-simple-page .fi-header-subheading{color:#788398!important;font-size:.925rem;line-height:1.6}
.fi-simple-page .fi-input-wrp{border-color:#dfe4ed!important;border-radius:.7rem!important;box-shadow:none}
.fi-simple-page .fi-input-wrp:focus-within{border-color:#355b96!important;box-shadow:0 0 0 3px rgba(53,91,150,.12)!important}
.fi-simple-page .fi-btn{min-height:2.9rem;border-radius:.7rem!important;font-weight:600;box-shadow:none}
@media(max-width:900px){.fi-simple-layout{grid-template-columns:minmax(0,1fr)!important}.fi-simple-layout::before{min-height:auto;padding:2rem 1.5rem;font-size:1rem;line-height:1.5;content:"NORTHWEST ERP\A A clearer view of your business."}.fi-simple-layout::after{display:none}.fi-simple-main-ctn,.fi-simple-main{min-height:auto;padding:2rem 1.25rem 3rem!important}}
@media(max-width:480px){.fi-simple-layout::before{padding:1.5rem 1.25rem}.fi-simple-page{border-radius:1rem!important;padding:1.25rem!important}}
</style>'))
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
