import { Component, inject } from '@angular/core';
import { CommonModule } from '@angular/common';
import { NotificationService } from '../../services/notification.service';

@Component({
  selector: 'app-notification',
  standalone: true,
  imports: [CommonModule],
  template: `
    <div *ngIf="notificationService.notification()"
         class="fixed top-5 right-5 z-50 animate-fade-in pointer-events-none">
      <div [ngClass]="{
        'bg-red-500': notificationService.notification()?.type === 'error',
        'bg-green-500': notificationService.notification()?.type === 'success',
        'bg-blue-500': notificationService.notification()?.type === 'info'
      }" class="text-white px-6 py-3 rounded-lg shadow-xl flex items-center space-x-3">
        <span class="font-medium">{{ notificationService.notification()?.message }}</span>
      </div>
    </div>
  `,
  styles: [`
    .animate-fade-in {
      animation: fadeIn 0.3s ease-out;
    }
    @keyframes fadeIn {
      from { opacity: 0; transform: translateY(-20px); }
      to { opacity: 1; transform: translateY(0); }
    }
  `]
})
export class NotificationComponent {
  public readonly notificationService = inject(NotificationService);
}
