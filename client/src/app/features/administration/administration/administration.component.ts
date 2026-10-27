import { Component } from '@angular/core';
import { RouterLink } from '@angular/router';

@Component({
  selector: 'app-amministrazione',
  imports: [RouterLink],
  template: `
    <div class="p-4 sm:p-6">
      <div class="flex flex-col sm:flex-row justify-between items-center gap-3 mb-6">
        <h1 class="text-xl sm:text-2xl font-bold text-slate-800">Amministrazione</h1>
      </div>

      <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
        <a [routerLink]="['/amministrazione/camera-e-letti']"
           class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 hover:border-blue-400 hover:shadow transition block">
          <div class="flex items-center justify-between">
            <h2 class="text-base font-semibold text-slate-800">Camera e Letti</h2>
            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="2" stroke="currentColor" class="w-5 h-5 text-slate-400">
              <path stroke-linecap="round" stroke-linejoin="round" d="M2 8.25c0-.966.784-1.75 1.75-1.75h16.5c.966 0 1.75.784 1.75 1.75v9c0 .966-.784 1.75-1.75 1.75H3.75A1.75 1.75 0 0 1 2 17.25v-9Zm2.25 4.5 1.5-1.5 2.5 2.5 3-3 4 4 3-3" />
            </svg>
          </div>
          <p class="mt-2 text-sm text-slate-500">Tipologie, stanze e letti. Gestione del catalogo e della capacità.</p>
        </a>
      </div>
    </div>
  `
})
export class AdministrationComponent {}
