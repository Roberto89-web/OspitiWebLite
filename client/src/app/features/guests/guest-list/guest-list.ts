import { ChangeDetectionStrategy, Component, OnInit, computed, inject, signal } from '@angular/core';
import { CommonModule } from '@angular/common';
import { Router, RouterModule } from '@angular/router';
import { GuestService } from '../../../core/services/guest.service';
import { NotificationService } from '../../../core/services/notification.service';
import { finalize } from 'rxjs/operators';
import { Guest, IcsAnomaly, ImportIcsResponse } from '../../../core/models/guest.model';

interface GuestListItem {
  kind: 'guest';
  key: string;
  start: string;
  end: string;
  guest: Guest;
}

@Component({
  selector: 'app-guest-list',
  imports: [CommonModule, RouterModule],
  changeDetection: ChangeDetectionStrategy.OnPush,
  templateUrl: './guest-list.html',
  styleUrls: ['./guest-list.scss'],
})
export class GuestListComponent implements OnInit {
  private readonly guestService = inject(GuestService);
  private readonly router = inject(Router);
  private readonly notificationService = inject(NotificationService);

  readonly guests = signal<Guest[]>([]);
  readonly isLoading = signal(false);
  readonly copiedId = signal<number | null>(null);

  // Manual copy fallback (shown when clipboard API silently fails)
  readonly manualCopyText = signal('');
  readonly copiedMessage = signal('');
  readonly showCopied = signal(false);

  // --- Selettore anno + filtro attivi ---
  readonly selectedYear = signal(new Date().getFullYear());
  readonly soloAttivi = signal(true);

  readonly showExportMenuId = signal<number | null>(null);

  // --- Sync calendario (import ICS) + risultato ---
  readonly showImportResult = signal(false);
  readonly importResult = signal<ImportIcsResponse | null>(null);
  readonly isSyncing = signal(false);

  /** Ordina le righe per `arrival_date` decrescente (le più recenti in cima). */
  private sortAnomalies(list: IcsAnomaly[]): IcsAnomaly[] {
    return [...list].sort((a, b) =>
      (b.arrival_date ?? '').localeCompare(a.arrival_date ?? ''));
  }

  /** Righe `stato='I'`: conservate per un token attivo, non più in ICS. */
  readonly anomaliesSorted = computed<IcsAnomaly[]>(() =>
    this.sortAnomalies((this.importResult()?.anomalies ?? []).filter(a => a.stato === 'I')));

  /** Righe `stato='O'`: prenotazioni in sovrapposizione (ultimo evento vince). */
  readonly overlapsSorted = computed<IcsAnomaly[]>(() =>
    this.sortAnomalies((this.importResult()?.anomalies ?? []).filter(a => a.stato === 'O')));

  /** Anni disponibili per il selettore (range -5…+10 attorno all'anno corrente). */
  readonly availableYears = computed(() => {
    const years: number[] = [];
    const base = this.selectedYear();
    for (let y = base - 5; y <= base + 10; y++) {
      years.push(y);
    }
    return years;
  });

  /**
   * Lista ospiti ordinata per data di arrivo.
   * Il filtraggio per anno è fatto lato server: `GET /guests?anno=`
   * restituisce solo gli elementi che intersecano l'anno selezionato.
   */
  readonly items = computed<GuestListItem[]>(() => {
    const list: GuestListItem[] = (Array.isArray(this.guests()) ? this.guests() : [])
      .map(g => ({
        kind: 'guest' as const,
        key: `g-${g.id}`,
        start: g.DataArrivo,
        end: g.DataPartenza,
        guest: g,
      }));
    return list.sort((a, b) => a.start.localeCompare(b.start));
  });

  ngOnInit(): void {
    this.loadGuests();
  }

  loadGuests(): void {
    this.isLoading.set(true);
    this.guestService.getGuests(this.selectedYear(), this.soloAttivi())
      .pipe(
        finalize(() => {
          this.isLoading.set(false);
        })
      )
      .subscribe({
        next: (data: Guest[]) => {
          this.guests.set(data);
        },
        error: (err) => {
          console.error('Error loading guests:', err);
        },
      });
  }

  changeYear(year: string): void {
    this.selectedYear.set(parseInt(year, 10));
    this.loadGuests();
  }

  setSoloAttivi(v: boolean): void {
    if (this.soloAttivi() === v) return;
    this.soloAttivi.set(v);
    this.loadGuests();
  }

  closeManualCopy(): void {
    this.manualCopyText.set('');
    this.copiedId.set(null);
    this.copiedMessage.set('');
    this.showCopied.set(false);
  }

  copyFromBanner(text: string): void {
    // Called from a direct button tap — user-gesture context is fresh,
    // so clipboard APIs always work even on iOS Safari.
    if (navigator.clipboard && navigator.clipboard.writeText) {
      navigator.clipboard.writeText(text).catch(() => this.fallbackCopyViaEl(text));
    } else {
      this.fallbackCopyViaEl(text);
    }

    this.copiedMessage.set('✓ Copiato!');
    this.showCopied.set(true);
    setTimeout(() => {
      this.showCopied.set(false);
      this.closeManualCopy();
    }, 2000);
  }

  private fallbackCopyViaEl(text: string): void {
    const textarea = document.createElement('textarea');
    textarea.value = text;
    textarea.setAttribute('readonly', '');
    textarea.style.position = 'fixed';
    textarea.style.left = '-9999px';
    textarea.style.top = '-9999px';
    textarea.style.opacity = '0';
    document.body.appendChild(textarea);
    textarea.focus();
    textarea.select();
    try { document.execCommand('copy'); } catch {}
    document.body.removeChild(textarea);
  }

  generateAndCopyLink(guest: Guest): void {
    this.guestService.generateRegistrationLink(guest.id).subscribe({
      next: (res: any) => {
        const link = res.link;
        this.manualCopyText.set(link);

        // Try silent copy in async context (works on desktop, iOS may block it).
        if (navigator.clipboard && navigator.clipboard.writeText) {
          navigator.clipboard.writeText(link).catch(() => {});
        }

        this.copiedId.set(guest.id);
        this.copiedMessage.set('Link copiato negli appunti!');
        this.showCopied.set(true);
        setTimeout(() => {
          this.copiedId.set(null);
          this.showCopied.set(false);
        }, 2500);

        this.notificationService.success('Link copiato negli appunti!');
      },
      error: (err) => {
        console.error('Error generating registration link:', err);
        this.manualCopyText.set('');
        this.copiedId.set(null);
        alert(err?.message || 'Errore generazione link. Riprova.');
      },
    });
  }

  toggleExportMenu(guest: Guest): void {
    const current = this.showExportMenuId();
    this.showExportMenuId.set(current === guest.id ? null : guest.id);
  }

  closeExportMenu(): void {
    this.showExportMenuId.set(null);
  }

  exportGuest(guest: Guest): void {
    this.closeExportMenu();
    this.guestService.exportGuests(guest.id_Registrazione).subscribe({
      next: (blob: Blob) => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `alloggiati_${guest.id_Registrazione}.txt`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
      },
      error: (err) => {
        console.error('Error exporting guest:', err);
        alert('Error exporting guest data');
      },
    });
  }

  exportRoss1000(guest: Guest): void {
    this.closeExportMenu();
    this.guestService.exportRoss1000(guest.id).subscribe({
      next: (blob: Blob) => {
        const url = window.URL.createObjectURL(blob);
        const a = document.createElement('a');
        a.href = url;
        a.download = `ross1000_${guest.id_Registrazione}.txt`;
        document.body.appendChild(a);
        a.click();
        window.URL.revokeObjectURL(url);
        document.body.removeChild(a);
      },
      error: (err) => {
        console.error('Error exporting ROSS1000 guest:', err);
        alert('Error exporting ROSS1000 guest');
      },
    });
  }

  editGuest(guest: Guest): void {
    this.router.navigate(['/guests/edit', guest.id]);
  }

  addNewGuest(): void {
    this.router.navigate(['/guests/new']);
  }

  syncCalendar(): void {
    const ok = window.confirm(
      'Sincronizzare il calendario? Le prenotazioni conflittuali verranno comunque ' +
      'importate e contrassegnate come "sovrapposizione"; quelle rimosse da ICS con ' +
      'un token attivo saranno conservate e segnate come "anomalia".');
    if (!ok) return;

    this.isSyncing.set(true);
    this.guestService.importIcs()
      .pipe(finalize(() => this.isSyncing.set(false)))
      .subscribe({
        next: (res) => {
          this.importResult.set(res ?? null);
          this.showImportResult.set(true);
          this.loadGuests();
          setTimeout(() => {
            document.querySelector<HTMLButtonElement>('[data-close-sync-modal]')?.focus();
          }, 0);
        },
        error: (err) => {
          console.error('Errore sincronizzazione calendario:', err);
          window.alert('Errore di sincronizzazione. Riprova più tardi.');
        },
      });
  }

  closeImportModal(): void {
    this.showImportResult.set(false);
    this.importResult.set(null);
    setTimeout(() => {
      document.querySelector<HTMLButtonElement>('[data-close-sync-modal]')?.focus();
    }, 0);
  }

  /** Formatta l'intervallo di una anomalia, gestendo date NULL. */
  formatAnomalyRange(a: IcsAnomaly): string {
    const in_ = a.arrival_date ?? '?';
    const out = a.departure_date ?? '?';
    return `${in_} → ${out}`;
  }

  /** `true` se l'ospite ha `anomaly === 'I'`. */
  guestHasAnomaly(g: Guest): boolean {
    return g.anomaly === 'I';
  }
}
