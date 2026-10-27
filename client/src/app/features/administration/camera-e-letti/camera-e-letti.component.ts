import { Component, OnInit, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { RouterLink } from '@angular/router';
import { FormsModule } from '@angular/forms';
import { finalize } from 'rxjs/operators';
import { ChangeDetectorRef } from '@angular/core';
import { CameraELettiService } from '../../../core/services/camera-e-letti.service';
import {
  Room,
  RoomType,
  CreateRoomTypeBody,
  CreateRoomBody,
} from '../../../core/models/camera-e-letti.model';
import { NotificationService } from '../../../core/services/notification.service';

@Component({
  selector: 'app-camera-e-letti',
  imports: [CommonModule, RouterLink, FormsModule],
  template: `
    <div class="p-4 sm:p-6">
      <div class="mb-6">
        <a [routerLink]="['/amministrazione']" class="text-sm text-blue-600 hover:text-blue-700">&larr; Amministrazione</a>
        <h1 class="text-xl sm:text-2xl font-bold text-slate-800">Camera e Letti</h1>
        <p class="text-sm text-slate-500 mt-1">Tipologie, stanze e letti. Il codice di una tipologia non è modificabile dopo la creazione.</p>
      </div>

      @if (isLoading()) {
        <div class="animate-pulse space-y-2">
          <div class="h-10 bg-slate-100 rounded"></div>
          <div class="h-10 bg-slate-100 rounded"></div>
          <div class="h-10 bg-slate-100 rounded"></div>
        </div>
      } @else {
        <!-- TIPOLOGIE -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5 mb-6">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-slate-800">Tipologie camera</h2>
            <button (click)="toggleNewType()"
                class="text-sm font-medium text-blue-600 hover:text-blue-700">
              {{ showNewType() ? 'Annulla' : '+ Nuova tipologia' }}
            </button>
          </div>

          @if (showNewType()) {
            <form (ngSubmit)="saveNewType()" class="grid grid-cols-2 sm:grid-cols-5 gap-3 mb-4 p-3 bg-slate-50 rounded-lg">
              <div class="col-span-2 sm:col-span-1">
                <label class="block text-xs text-slate-500 mb-1">Codice</label>
                <input name="code" [ngModel]="newType().code" (ngModelChange)="newType.set({...newType(), code:$event})" placeholder="es. STD"
                  class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm uppercase focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div class="col-span-2 sm:col-span-2">
                <label class="block text-xs text-slate-500 mb-1">Nome</label>
                <input name="name" [ngModel]="newType().name" (ngModelChange)="newType.set({...newType(), name:$event})" placeholder="es. Standard"
                  class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label class="block text-xs text-slate-500 mb-1">Posti</label>
                <input name="capacity" type="number" min="1" max="20" [ngModel]="newType().capacity" (ngModelChange)="newType.set({...newType(), capacity:+$event||0})"
                  class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label class="block text-xs text-slate-500 mb-1">Letti def.</label>
                <input name="beds_default" type="number" min="1" max="20" [ngModel]="newType().beds_default" (ngModelChange)="newType.set({...newType(), beds_default:+$event||0})"
                  class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div class="col-span-2 sm:col-span-5 flex justify-end">
                <button type="submit" [disabled]="isSaving()"
                  class="bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium rounded px-4 py-1.5">
                  {{ isSaving() ? 'Salvataggio…' : 'Crea tipologia' }}
                </button>
              </div>
            </form>
          }

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs font-semibold text-slate-500 border-b border-slate-200">
                  <th class="py-2 pr-3">Code</th>
                  <th class="py-2 pr-3">Nome</th>
                  <th class="py-2 pr-3 text-right">Posti</th>
                  <th class="py-2 pr-3 text-right">Letti def.</th>
                  <th class="py-2 pr-3 text-center">Attiva</th>
                  <th class="py-2 text-right">Azioni</th>
                </tr>
              </thead>
              <tbody>
                @for (t of roomTypes(); track t.id) {
                  <tr class="border-b border-slate-100">
                    <td class="py-2 pr-3 font-mono text-xs">{{ t.code }}</td>
                    <td class="py-2 pr-3">{{ t.name }}</td>
                    <td class="py-2 pr-3 text-right">{{ t.capacity }}</td>
                    <td class="py-2 pr-3 text-right">{{ t.beds_default }}</td>
                    <td class="py-2 pr-3 text-center">{{ t.active ? '✅' : '⛔' }}</td>
                    <td class="py-2 text-right whitespace-nowrap">
                      <button (click)="toggleActiveType(t)"
                        class="text-xs text-slate-600 hover:text-blue-600 mr-3">
                        {{ t.active ? 'Disattiva' : 'Attiva' }}
                      </button>
                      <button (click)="onDeleteType(t)"
                        class="text-xs text-red-500 hover:text-red-700">
                        Elimina
                      </button>
                    </td>
                  </tr>
                } @empty {
                  <tr><td colspan="6" class="py-4 text-center text-slate-400">Nessuna tipologia</td></tr>
                }
              </tbody>
            </table>
          </div>
        </section>

        <!-- STANZE -->
        <section class="bg-white rounded-xl shadow-sm border border-slate-200 p-5">
          <div class="flex items-center justify-between mb-4">
            <h2 class="text-base font-semibold text-slate-800">Stanze</h2>
            <button (click)="toggleNewRoom()"
                class="text-sm font-medium text-blue-600 hover:text-blue-700">
              {{ showNewRoom() ? 'Annulla' : '+ Nuova stanza' }}
            </button>
          </div>

          @if (showNewRoom()) {
            <form (ngSubmit)="saveNewRoom()" class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4 p-3 bg-slate-50 rounded-lg">
              <div>
                <label class="block text-xs text-slate-500 mb-1">Tipologia</label>
                <select name="room_type_id" [ngModel]="newRoom().room_type_id" (ngModelChange)="newRoom.set({...newRoom(), room_type_id:+$event||0})"
                  class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                  @for (t of activeTypes(); track t.id) {
                    <option [value]="t.id">{{ t.code }} — {{ t.name }}</option>
                  }
                </select>
              </div>
              <div>
                <label class="block text-xs text-slate-500 mb-1">Etichetta</label>
                <input name="label" [ngModel]="newRoom().label" (ngModelChange)="newRoom.set({...newRoom(), label:$event})" placeholder="es. Camera 1"
                  class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div>
                <label class="block text-xs text-slate-500 mb-1">Letti</label>
                <input name="beds" type="number" min="1" max="20" [ngModel]="newRoom().beds" (ngModelChange)="newRoom.set({...newRoom(), beds:+$event||0})"
                  class="w-full border border-slate-300 rounded px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500" />
              </div>
              <div class="flex items-end">
                <button type="submit" [disabled]="isSaving()"
                  class="w-full bg-blue-600 hover:bg-blue-700 disabled:opacity-50 text-white text-sm font-medium rounded px-4 py-1.5">
                  {{ isSaving() ? 'Salvataggio…' : 'Crea stanza' }}
                </button>
              </div>
            </form>
          }

          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="text-left text-xs font-semibold text-slate-500 border-b border-slate-200">
                  <th class="py-2 pr-3">Etichetta</th>
                  <th class="py-2 pr-3">Tipologia</th>
                  <th class="py-2 pr-3 text-right">Letti</th>
                  <th class="py-2 pr-3 text-center">Attiva</th>
                  <th class="py-2 text-right">Azioni</th>
                </tr>
              </thead>
              <tbody>
                @for (r of rooms(); track r.id) {
                  <tr class="border-b border-slate-100">
                    <td class="py-2 pr-3">{{ r.label }}</td>
                    <td class="py-2 pr-3 font-mono text-xs">{{ r.room_type_code }}</td>
                    <td class="py-2 pr-3 text-right">{{ r.beds }}</td>
                    <td class="py-2 pr-3 text-center">{{ r.active ? '✅' : '⛔' }}</td>
                    <td class="py-2 text-right whitespace-nowrap">
                      <button (click)="toggleActiveRoom(r)"
                        class="text-xs text-slate-600 hover:text-blue-600 mr-3">
                        {{ r.active ? 'Disattiva' : 'Attiva' }}
                      </button>
                      <button (click)="onDeleteRoom(r)"
                        class="text-xs text-red-500 hover:text-red-700">
                        Elimina
                      </button>
                    </td>
                  </tr>
                } @empty {
                  <tr><td colspan="5" class="py-4 text-center text-slate-400">Nessuna stanza</td></tr>
                }
              </tbody>
            </table>
          </div>
        </section>
      }
    </div>
  `
})
export class CameraELettiComponent implements OnInit {
  private readonly svc = inject(CameraELettiService);
  private readonly cdr = inject(ChangeDetectorRef);
  private readonly notify = inject(NotificationService);

  protected readonly isLoading = signal(false);
  protected readonly isSaving = signal(false);
  protected readonly roomTypes = signal<RoomType[]>([]);
  protected readonly rooms = signal<Room[]>([]);

  protected readonly showNewType = signal(false);
  protected readonly showNewRoom = signal(false);

  protected readonly newType = signal<CreateRoomTypeBody>({ code: '', name: '', capacity: 2, beds_default: 1 });
  protected readonly newRoom = signal<CreateRoomBody>({ room_type_id: 0, label: '', beds: 1 });

  protected readonly activeTypes = () => this.roomTypes().filter((t) => t.active);

  ngOnInit(): void {
    this.load();
  }

  protected get firstActiveTypeId(): number {
    const t = this.activeTypes()[0];
    return t ? t.id : 0;
  }

  private load(): void {
    this.isLoading.set(true);
    this.svc.listRooms()
      .pipe(finalize(() => { this.isLoading.set(false); this.cdr.markForCheck(); }))
      .subscribe({
        next: (d) => { this.roomTypes.set(d.room_types); this.rooms.set(d.rooms); this.cdr.markForCheck(); },
        error: (e) => { this.notify.error((e as Error).message); this.cdr.markForCheck(); },
      });
  }

  // ── Tipologie ────────────────────────────────────────────────
  protected toggleNewType(): void {
    const open = !this.showNewType();
    this.showNewType.set(open);
    this.showNewRoom.set(false);
    if (open) this.newType.set({ code: '', name: '', capacity: 2, beds_default: 1 });
    this.cdr.markForCheck();
  }

  protected saveNewType(): void {
    const v = this.newType();
    const body: CreateRoomTypeBody = {
      code: v.code.trim().toUpperCase(),
      name: v.name.trim(),
      capacity: Number(v.capacity) || 2,
      beds_default: Number(v.beds_default) || 1,
    };
    this.isSaving.set(true);
    this.svc.createRoomType(body)
      .pipe(finalize(() => { this.isSaving.set(false); this.cdr.markForCheck(); }))
      .subscribe({
        next: () => { this.notify.success('Tipologia creata'); this.showNewType.set(false); this.load(); },
        error: (e) => { this.notify.error((e as Error).message); },
      });
  }

  protected toggleActiveType(t: RoomType): void {
    this.svc.updateRoomType(t.id, { active: !t.active })
      .subscribe({
        next: () => { this.notify.success(t.active ? 'Tipologia disattivata' : 'Tipologia attivata'); this.load(); },
        error: (e) => { this.notify.error((e as Error).message); },
      });
  }

  protected onDeleteType(t: RoomType): void {
    if (!window.confirm('Eliminare la tipologia ' + t.name + ' (' + t.code + ')?')) return;
    this.svc.deleteRoomType(t.id)
      .subscribe({
        next: () => { this.notify.success('Tipologia eliminata'); this.load(); },
        error: (e) => { this.notify.error((e as Error).message); },
      });
  }

  // ── Stanze ───────────────────────────────────────────────────
  protected toggleNewRoom(): void {
    const open = !this.showNewRoom();
    this.showNewRoom.set(open);
    this.showNewType.set(false);
    if (open) {
      this.newRoom.set({
        room_type_id: this.firstActiveTypeId,
        label: '',
        beds: this.activeTypes()[0]?.beds_default ?? 1,
      });
    }
    this.cdr.markForCheck();
  }

  protected saveNewRoom(): void {
    const v = this.newRoom();
    const body: CreateRoomBody = {
      room_type_id: Number(v.room_type_id) || 0,
      label: v.label.trim(),
      beds: Number(v.beds) || 1,
    };
    if (!body.room_type_id) { this.notify.error('Seleziona una tipologia'); return; }
    this.isSaving.set(true);
    this.svc.createRoom(body)
      .pipe(finalize(() => { this.isSaving.set(false); this.cdr.markForCheck(); }))
      .subscribe({
        next: () => { this.notify.success('Stanza creata'); this.showNewRoom.set(false); this.load(); },
        error: (e) => { this.notify.error((e as Error).message); },
      });
  }

  protected toggleActiveRoom(r: Room): void {
    this.svc.updateRoom(r.id, { active: !r.active })
      .subscribe({
        next: () => { this.notify.success(r.active ? 'Stanza disattivata' : 'Stanza attivata'); this.load(); },
        error: (e) => { this.notify.error((e as Error).message); },
      });
  }

  protected onDeleteRoom(r: Room): void {
    if (!window.confirm('Eliminare la stanza ' + r.label + '?')) return;
    this.svc.deleteRoom(r.id)
      .subscribe({
        next: () => { this.notify.success('Stanza eliminata'); this.load(); },
        error: (e) => { this.notify.error((e as Error).message); },
      });
  }
}
