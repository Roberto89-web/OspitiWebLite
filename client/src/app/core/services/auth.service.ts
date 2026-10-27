import { Injectable } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, tap } from 'rxjs';
import { API_URL } from '../constants';
import { LoginRequest, AuthResponse } from '../models/auth.model';
@Injectable({
  providedIn: 'root'
})
export class AuthService {
  private readonly TOKEN_KEY = 'auth_token';
  private readonly apiUrl = API_URL;

  constructor(private http: HttpClient) {}

  login(credentials: LoginRequest): Observable<AuthResponse> {
    return this.http.post<AuthResponse>(`${this.apiUrl}/login`, credentials).pipe(
      tap(response => this.setToken(response.token))
    );
  }

  logout(): void {
    // Prima revoco il token in server; poi lo scarto in locale. Se la revoca
    // fallisce (offline/network) il client resta comunque non autenticato.
    this.http
      .post<void>(`${this.apiUrl}/api/logout`, {}, { withCredentials: true })
      .subscribe({
        complete: () => this.finishLogout(),
        error: () => this.finishLogout(),
      });
  }

  private finishLogout(): void {
    this.removeToken();
    window.location.href = '/login';
  }

  setToken(token: string): void {
    localStorage.setItem(this.TOKEN_KEY, token);
  }

  getToken(): string | null {
    return localStorage.getItem(this.TOKEN_KEY);
  }

  isAuthenticated(): boolean {
    return !!this.getToken();
  }

  removeToken(): void {
    localStorage.removeItem(this.TOKEN_KEY);
  }
}
