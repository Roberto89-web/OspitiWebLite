import { HttpInterceptorFn, HttpErrorResponse } from '@angular/common/http';
import { inject } from '@angular/core';
import { AuthService } from '../services/auth.service';
import { NotificationService } from '../services/notification.service';
import { catchError, throwError } from 'rxjs';

export const authInterceptor: HttpInterceptorFn = (req, next) => {
  const authService = inject(AuthService);
  const notificationService = inject(NotificationService);
  const token = authService.getToken();

    // Determine if the token should be attached
  const isLogout = req.url.endsWith('/api/logout');
  let request = req;
  if (!isLogout && !req.url.endsWith('/login') && token) {
    request = req.clone({
      setHeaders: {
        Authorization: `Bearer ${token}`
      }
    });
  }

  // Execute the request and intercept the response for errors
  return next(request).pipe(
    catchError((error: HttpErrorResponse) => {
      if (error.status === 401) {
        notificationService.error('Sessione scaduta. Effettua nuovamente l\'accesso.');
        authService.logout();
      } else if (error.status >= 500) {
        notificationService.error('Errore del server. Riprova più tardi.');
      } else if (error.status === 0) {
        notificationService.error('Impossibile connettersi al server. Verifica la connessione.');
      } else {
        notificationService.error(`Si è verificato un errore: ${error.message || 'Errore sconosciuto'}`);
      }
      // Propagate the error so components/services can still handle it
      return throwError(() => error);
    })
  );
};
