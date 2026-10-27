import { Injectable, signal } from '@angular/core';

export interface Notification {
  message: string;
  type: 'success' | 'error' | 'info';
  duration?: number;
}

@Injectable({
  providedIn: 'root'
})
export class NotificationService {
  // Signal to hold the current notification state
  notification = signal<Notification | null>(null);

  show(message: string, type: 'success' | 'error' | 'info' = 'info', duration: number = 3000) {
    this.notification.set({ message, type, duration });

    setTimeout(() => {
      this.clear();
    }, duration);
  }

  error(message: string, duration: number = 5000) {
    this.show(message, 'error', duration);
  }

  success(message: string, duration: number = 3000) {
    this.show(message, 'success', duration);
  }

  info(message: string, duration: number = 3000) {
    this.show(message, 'info', duration);
  }

  clear() {
    this.notification.set(null);
  }
}
