import { Injectable } from '@angular/core';
import { HttpClient, HttpParams } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../constants';
import { Guest, GuestSaveRequest, ImportIcsResponse, RegistrationLinkResponse, RegistrationSubmitRequest } from '../models/guest.model';

@Injectable({
  providedIn: 'root'
})
export class GuestService {
  private readonly apiUrl = API_URL;

  constructor(private http: HttpClient) {}

  getGuests(anno?: number, soloAttivi?: boolean): Observable<Guest[]> {
    let params = new HttpParams();
    if (anno != null) params = params.set('anno', String(anno));
    if (soloAttivi) params = params.set('solo_attivi', 'true');
    return this.http.get<Guest[]>(`${this.apiUrl}/guests`, { params });
  }

  getGuestById(id: number): Observable<Guest> {
    return this.http.get<Guest>(`${this.apiUrl}/guests/${id}`);
  }

  exportGuests(registrationId?: number): Observable<Blob> {
    let url = `${this.apiUrl}/guests/export`;
    if (registrationId) {
      url += `?registration_id=${registrationId}`;
    }
    return this.http.get(url, { responseType: 'blob' });
  }

  exportRoss1000(guestId: number): Observable<Blob> {
    return this.http.get(`${this.apiUrl}/guests/export-ross1000?guest_id=${guestId}`, { responseType: 'blob' });
  }

  saveGuest(guest: GuestSaveRequest): Observable<{message: string, id: number}> {
    return this.http.post<{message: string, id: number}>(`${this.apiUrl}/guests`, guest);
  }

  deleteGuest(id: number): Observable<{ ok: boolean; message?: string; err?: string }> {
    return this.http.delete<{ ok: boolean; message?: string; err?: string }>(`${this.apiUrl}/guests/${id}`);
  }

  submitRegistration(request: RegistrationSubmitRequest): Observable<{ message: string }> {
    return this.http.post<{ message: string }>(`${this.apiUrl}/api/register/submit`, request);
  }

  generateRegistrationLink(id: number): Observable<RegistrationLinkResponse> {
    return this.http.post<RegistrationLinkResponse>(`${this.apiUrl}/api/guests/${id}/generate-link`, {});
  }

  /** Importa l'ICS: `force=true` → non blocca sui conflitti, li marca (stato='O'). */
  importIcs(force = true): Observable<ImportIcsResponse> {
    return this.http.post<ImportIcsResponse>(`${this.apiUrl}/api/import-ics`, { force });
  }
}
