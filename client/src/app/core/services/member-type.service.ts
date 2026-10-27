import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable } from 'rxjs';
import { API_URL } from '../constants';
import { LookupTipoAlloggiato } from '../models/guest.model';

@Injectable({
  providedIn: 'root'
})
export class MemberTypeService {
  private readonly apiUrl = API_URL;

  constructor(private http: HttpClient) {}

  getTipoAlloggiato(): Observable<LookupTipoAlloggiato[]> {
    return this.http.get<LookupTipoAlloggiato[]>(`${this.apiUrl}/api/lookups/tipo_alloggiato`);
  }
}
