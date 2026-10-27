export interface RegistrationPerson {
  first_name: string;
  last_name: string;
  email: string;
  phone: string;
  gender: number;
  birth_date: string;
  birth_city_code: string;
  birth_prov_code: string;
  birth_state_code: string;
  citizenship_state_code: string;
  doc_type: string;
  doc_number: string;
  doc_issue_city: string;
}

export interface RegistrationSubmitRequest {
  token: string;
  persons: RegistrationPerson[];
}

export interface Guest {
  id: number;
  id_Registrazione: number;
  Tipo: number;
  NomeCapofamiglia: string;
  CognomeCapofamiglia: string;
  DataArrivo: string;
  DataPartenza: string;
  GiorniSoggiorno: number;
  Email: string;
  NumOspiti: number;
  Stato: number;
  registrazioneConfermata: boolean;
  /** 'I' = non più presente nel calendario ICS, conservato per un token attivo;
   *  'O' = sovrapposizione confermata (ultimo evento vince). */
  anomaly?: 'I' | 'O' | null;
}

export interface GuestSaveRequest {
  id?: number;
  NomeCapofamiglia: string;
  CognomeCapoFamiglia: string;
  NumOspiti: number;
  email: string;
  TipoOspite: number;
  DataArrivo: string; // YYYY-MM-DD
  DataPartenza: string; // YYYY-MM-DD
}

export interface RegistrationLinkResponse {
  token: string;
  expires_at: string;
  link: string;
  guest: Guest;
}

export interface LookupTipoAlloggiato {
  codice: string;
  descrizione: string;
}

/** Elemento nella lista `anomalies[]` della risposta di import ICS. */
export interface IcsAnomaly {
  guest_id: number;
  arrival_date: string | null;
  departure_date: string | null;
  /** 'I' = conservato per token attivo; 'O' = sovrapposizione rilevata. */
  stato: 'I' | 'O';
  reason?: string | null;
}

/** Risposta di POST /api/import-ics (estesa). */
export interface ImportIcsResponse {
  message: string;
  guests_imported: number;
  /** Numero di eventi ICS non validati (summary non booking / date non parseate). */
  skipped?: number;
  errors?: unknown[];
  /** Dettagli tecnici dei conflitti rilevati (kind, details). */
  conflicts?: unknown[];
  /** Lista dettagliata delle anomalie ('I') e sovrapposizioni ('O'). */
  anomalies?: IcsAnomaly[];
}
