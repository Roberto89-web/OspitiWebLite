import { Injectable, inject } from '@angular/core';
import { HttpClient } from '@angular/common/http';
import { Observable, throwError } from 'rxjs';
import { map, catchError } from 'rxjs/operators';
import { HttpErrorResponse } from '@angular/common/http';
import { API_URL } from '../constants';
import {
  Room,
  RoomType,
  RoomsPayload,
  CreateRoomTypeBody,
  UpdateRoomTypeBody,
  CreateRoomBody,
  UpdateRoomBody,
  ApiEnvelope,
} from '../models/camera-e-letti.model';

/** Recupera il messaggio errore dal body `{ok:false, err}` o fa fallback a `msg.message`. */
function httpError(err: unknown): Error {
  const e = err as HttpErrorResponse | undefined;
  const body = e?.error;
  if (body && typeof body === 'object' && typeof (body as any).err === 'string' && (body as any).err) {
    return new Error((body as any).err);
  }
  return new Error(e?.message || 'Errore di rete');
}

function errPipe<T>() {
  return catchError<T, never>((err) => { throw httpError(err); });
}

/**
 * Gestione amministrativa camere & tipologie (API §13.7).
 * Le risposte arrivano nell'envelope `{ok:true, data}`; in caso di `ok:false`
 * o HTTP 4xx/5xx si propaga un errore con il messaggio del server.
 */
@Injectable({ providedIn: 'root' })
export class CameraELettiService {
  private readonly http = inject(HttpClient);
  private readonly apiUrl = API_URL;

  private unwrap<T>(obs: Observable<ApiEnvelope<T>>): Observable<T> {
    return obs.pipe(
      map((env) => {
        if (env && env.ok === false) throw new Error(env.err || 'Errore imprevisto dal server');
        return env.data;
      }),
      errPipe()
    );
  }

  // Room Types ──────────────────────────────────────────────
  listRoomTypes(): Observable<RoomType[]> {
    return this.unwrap(this.http.get<ApiEnvelope<RoomType[]>>(`${this.apiUrl}/api/admin/room-types`));
  }

  createRoomType(body: CreateRoomTypeBody): Observable<RoomType> {
    return this.unwrap(this.http.post<ApiEnvelope<RoomType>>(`${this.apiUrl}/api/admin/room-types`, body));
  }

  updateRoomType(id: number, body: UpdateRoomTypeBody): Observable<RoomType> {
    return this.unwrap(this.http.put<ApiEnvelope<RoomType>>(`${this.apiUrl}/api/admin/room-types/${id}`, body));
  }

  deleteRoomType(id: number): Observable<RoomType> {
    return this.unwrap(this.http.delete<ApiEnvelope<RoomType>>(`${this.apiUrl}/api/admin/room-types/${id}`));
  }

  // Rooms ────────────────────────────────────────────────────
  listRooms(): Observable<RoomsPayload> {
    return this.unwrap(this.http.get<ApiEnvelope<RoomsPayload>>(`${this.apiUrl}/api/admin/rooms`));
  }

  createRoom(body: CreateRoomBody): Observable<Room> {
    return this.unwrap(this.http.post<ApiEnvelope<Room>>(`${this.apiUrl}/api/admin/rooms`, body));
  }

  updateRoom(id: number, body: UpdateRoomBody): Observable<Room> {
    return this.unwrap(this.http.put<ApiEnvelope<Room>>(`${this.apiUrl}/api/admin/rooms/${id}`, body));
  }

  deleteRoom(id: number): Observable<Room> {
    return this.unwrap(this.http.delete<ApiEnvelope<Room>>(`${this.apiUrl}/api/admin/rooms/${id}`));
  }
}
