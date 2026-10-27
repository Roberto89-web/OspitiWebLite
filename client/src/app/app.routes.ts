import { Routes } from '@angular/router';
import { authGuard } from './core/guards/auth.guard';

export const routes: Routes = [
  {
    path: 'login',
    loadComponent: () =>
      import('./features/auth/login/login').then((m) => m.LoginComponent),
  },
  {
    path: 'guests',
    loadComponent: () =>
      import('./features/guests/guest-list/guest-list').then(
        (m) => m.GuestListComponent,
      ),
    canActivate: [authGuard],
  },
  {
    path: 'guests/new',
    loadComponent: () =>
      import('./features/guests/guest-form/guest-form').then(
        (m) => m.GuestFormComponent,
      ),
    canActivate: [authGuard],
  },
  {
    path: 'guests/edit/:id',
    loadComponent: () =>
      import('./features/guests/guest-form/guest-form').then(
        (m) => m.GuestFormComponent,
      ),
    canActivate: [authGuard],
  },
  {
    path: 'amministrazione',
    loadComponent: () =>
      import('./features/administration/administration/administration.component').then(
        (m) => m.AdministrationComponent,
      ),
    canActivate: [authGuard],
  },
  {
    path: 'amministrazione/camera-e-letti',
    loadComponent: () =>
      import('./features/administration/camera-e-letti/camera-e-letti.component').then(
        (m) => m.CameraELettiComponent,
      ),
    canActivate: [authGuard],
  },
  { path: '', redirectTo: '/guests', pathMatch: 'full' },
  { path: '**', redirectTo: '/login' },
];
