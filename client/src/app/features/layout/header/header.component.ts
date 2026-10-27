import { Component, inject } from '@angular/core';
import { RouterLink } from '@angular/router';
import { AuthService } from '../../../core/services/auth.service';

@Component({
  selector: 'app-header',
  standalone: true,
  imports: [RouterLink],
  template: `
    <nav class="bg-slate-800 text-white p-4 shadow-md">
      <div class="container mx-auto flex justify-between items-center">
        <div class="text-xl font-bold">OspitiWebClient</div>
        <div class="flex gap-6 items-center">
          <a routerLink="/guests" class="hover:text-slate-300 transition-colors">Ospiti</a>
          <a routerLink="/amministrazione" class="hover:text-slate-300 transition-colors">Amministrazione</a>
          @if (auth.isAuthenticated()) {
            <button
              type="button"
              (click)="onLogout()"
              class="bg-slate-700 hover:bg-slate-600 transition-colors px-4 py-2 rounded"
            >
              Esci
            </button>
          }
        </div>
      </div>
    </nav>
  `
})
export class HeaderComponent {
  protected auth = inject(AuthService);

  onLogout(): void {
    this.auth.logout();
  }
}
