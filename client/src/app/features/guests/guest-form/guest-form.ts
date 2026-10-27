import { Component, OnInit, OnDestroy, ChangeDetectorRef } from '@angular/core';
import { CommonModule } from '@angular/common';
import { FormBuilder, FormGroup, ReactiveFormsModule, Validators } from '@angular/forms';
import { Router, ActivatedRoute, RouterLink } from '@angular/router';
import { GuestService } from '../../../core/services/guest.service';
import { MemberTypeService } from '../../../core/services/member-type.service';
import { NotificationService } from '../../../core/services/notification.service';
import { LookupTipoAlloggiato, GuestSaveRequest } from '../../../core/models/guest.model';

/** Codici tipo-alloggiato capofamiglia selezionabili in questa pagina (mai 19/20 componenti). */
const HEAD_TYPE_CODES = new Set([16, 17, 18]);

@Component({
  selector: 'app-guest-form',
  imports: [CommonModule, ReactiveFormsModule, RouterLink],
  templateUrl: './guest-form.html',
  styleUrls: ['./guest-form.scss']
})
export class GuestFormComponent implements OnInit, OnDestroy {
  guestForm: FormGroup;
  tipoAlloggiati: LookupTipoAlloggiato[] = [];
  private tipoAutoFillSub: { unsubscribe(): void } | null = null;
  isLoading = false;
  isSubmitted = false;
  isEditMode = false;
  guestId: number | null = null;

  /** Tipi capofamiglia selezionabili nel dropdown (16 OSPITE SINGOLO / 17 CAPO FAMIGLIA / 18 CAPO GRUPPO). */
  get headTypes(): LookupTipoAlloggiato[] {
    return this.tipoAlloggiati.filter((t) => HEAD_TYPE_CODES.has(+t.codice));
  }

  // --- Elimina guest (soft delete, admin) ---
  showDeleteDialog = false;
  isDeleting = false;
  deleteError = '';
  deleteSuccess = '';

  constructor(
    private fb: FormBuilder,
    private guestService: GuestService,
    private memberTypeService: MemberTypeService,
    private router: Router,
    private route: ActivatedRoute,
    private cdr: ChangeDetectorRef,
    private notificationService: NotificationService
  ) {
    this.guestForm = this.fb.group({
      NomeCapofamiglia: ['', [Validators.required]],
      CognomeCapoFamiglia: ['', [Validators.required]],
      NumOspiti: [1, [Validators.required, Validators.min(1)]],
      email: ['', [Validators.required, Validators.email]],
      TipoOspite: [null, [Validators.required]],
      DataArrivo: ['', [Validators.required]],
      DataPartenza: ['', [Validators.required]]
    });
  }

  ngOnInit(): void {
    this.loadMemberTypes();
    this.checkEditMode();
    this.setupTipoAutoFill();
  }

  ngOnDestroy(): void {
    this.tipoAutoFillSub?.unsubscribe();
  }

  /**
   * Auto-compila il tipo ospite in base al numero di ospiti,
   * SOLO se il campo `TipoOspite` non è ancora stato compilato
   * (null/empty). Se ha già un valore l'utente può aver scelto
   * consapevolmente 17/18 e non va sovrascritto mai.
   *
   * Regola: 1 ospite → 16 (OSPITE SINGOLO); ≥ 2 ospiti → 17 (CAPO FAMIGLIA).
   * L'utente può poi scegliere manualmente 18 (CAPO GRUPPO).
   *
   * Non si esegue al mount (default form NumOspiti=1 porterebbe subito a 16
   * e bloccerebbe il guard "solo se vuoto"); interviene solo al primo
   * input dell'utente su Numero Ospiti. In edit `patchValue` porta già un
   * `TipoOspite` → watcher no-op.
   */
  private setupTipoAutoFill(): void {
    if (this.tipoAutoFillSub) this.tipoAutoFillSub.unsubscribe();
    const numCtrl = this.guestForm.get('NumOspiti');
    const tipoCtrl = this.guestForm.get('TipoOspite');
    if (!numCtrl || !tipoCtrl) return;
    const apply = (n: number | null) => {
      const cur = tipoCtrl.value;
      if (cur !== null && cur !== undefined && cur !== '' && cur !== 0) return;
      if (n === null || n === undefined) return;
      tipoCtrl.setValue(n === 1 ? 16 : 17);
    };
    this.tipoAutoFillSub = numCtrl.valueChanges.subscribe({ next: (n) => apply(n) });
  }

  checkEditMode(): void {
    const id = this.route.snapshot.paramMap.get('id');
    if (id) {
      this.isEditMode = true;
      this.guestId = +id;
      this.loadGuestData(this.guestId);
    }
  }

  loadGuestData(id: number): void {
    this.isLoading = true;
    this.guestService.getGuestById(id).subscribe({
      next: (guest) => {
        this.guestForm.patchValue({
          NomeCapofamiglia: guest.NomeCapofamiglia,
          CognomeCapoFamiglia: guest.CognomeCapofamiglia,
          NumOspiti: guest.NumOspiti,
          email: guest.Email,
          TipoOspite: guest.Tipo,
          DataArrivo: guest.DataArrivo,
          DataPartenza: guest.DataPartenza
        });
        this.isLoading = false;
        this.cdr.markForCheck();
      },
      error: (err) => {
        if (err && err.status === 404) {
          alert('Guest not found');
          this.router.navigate(['/guests']);
        } else {
          console.error('Error loading guest:', err);
          alert('Error loading guest data');
        }
        this.isLoading = false;
        this.cdr.markForCheck();
      }
    });
  }

  loadMemberTypes(): void {
    this.memberTypeService.getTipoAlloggiato().subscribe({
      next: (types: LookupTipoAlloggiato[]) => this.tipoAlloggiati = types,
      error: (err) => console.error('Error loading tipo alloggiato:', err)
    });
  }

  // --- Elimina guest (soft delete) ---
  openDeleteDialog(): void {
    if (!this.guestId) return;
    this.showDeleteDialog = true;
    this.deleteError = '';
    this.deleteSuccess = '';
    this.isDeleting = false;
  }

  closeDeleteDialog(): void {
    this.showDeleteDialog = false;
    this.deleteError = '';
    this.deleteSuccess = '';
    this.isDeleting = false;
  }

  onDeleteKeydown(event: KeyboardEvent): void {
    if (event.key === 'Escape') {
      event.preventDefault();
      this.closeDeleteDialog();
    }
  }

  confirmDelete(): void {
    if (!this.guestId || this.isDeleting) return;
    this.isDeleting = true;
    this.deleteError = '';

    this.guestService.deleteGuest(this.guestId).subscribe({
      next: () => {
        this.deleteSuccess = 'Ospite eliminato con successo.';
        this.notificationService.success('Ospite eliminato.');
        setTimeout(() => this.router.navigate(['/guests']), 1200);
      },
      error: (err: any) => {
        const serverMsg = err?.error?.err || err?.error?.message || '';
        if (err?.status === 401) this.deleteError = 'Sessione scaduta. Effettua di nuovo il login.';
        else if (err?.status === 404) this.deleteError = 'Ospite non trovato.';
        else if (serverMsg) this.deleteError = serverMsg;
        else this.deleteError = 'Errore nell\u2019eliminazione. Riprova.';
        setTimeout(() => {
          this.isDeleting = false;
          this.cdr.markForCheck();
        }, 0);
      }
    });
  }

  onSubmit() {
    if (this.guestForm.invalid) return;

    const arrivalDate = new Date(this.guestForm.value.DataArrivo);
    const departureDate = new Date(this.guestForm.value.DataPartenza);

    if (departureDate < arrivalDate) {
      alert('Departure date cannot be before arrival date');
      return;
    }

    this.isLoading = true;
    const payload: GuestSaveRequest = {
      ...this.guestForm.value,
      id: this.isEditMode ? this.guestId : undefined
    };

    this.guestService.saveGuest(payload).subscribe({
      next: () => {
        this.isSubmitted = true;
        setTimeout(() => this.router.navigate(['/guests']), 2000);
        this.isLoading = false;
      },
      error: (err: any) => {
        console.error('Error saving guest:', err);
        alert('Error saving guest');
        this.isLoading = false;
      }
    });
  }
}
