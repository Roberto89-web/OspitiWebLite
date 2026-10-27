/**
 * Modelli per la gestione amministrativa di camere e tipologie (API_DOCS §13).
 */

/** Tipologia camera (room_types). `code` immutabile dopo la creazione. */
export interface RoomType {
  id: number;
  code: string;
  name: string;
  capacity: number;
  beds_default: number;
  active: boolean;
}

/** Stanza fisica (rooms). */
export interface Room {
  id: number;
  label: string;
  beds: number;
  room_type_id: number;
  room_type_code: string;
  room_type_name: string;
  capacity: number | null;
  active: boolean;
}

/** Payload `GET /api/admin/rooms`: stanze + vocabolario tipologie in un unico GET. */
export interface RoomsPayload {
  rooms: Room[];
  room_types: RoomType[];
}

// ── Corpi di richiesta (in → API) ────────────────────────────────

export interface CreateRoomTypeBody {
  code: string;
  name: string;
  capacity: number;
  beds_default?: number;
}

export interface UpdateRoomTypeBody {
  name?: string;
  capacity?: number;
  beds_default?: number;
  active?: boolean;
}

export interface CreateRoomBody {
  room_type_id: number;
  label: string;
  beds?: number;
}

export interface UpdateRoomBody {
  label?: string;
  beds?: number;
  room_type_id?: number;
  active?: boolean;
}

/** Envelope comune delle risposte amministrate (ok/data/err). */
export interface ApiEnvelope<T> {
  ok: boolean;
  data: T;
  err?: string;
}
