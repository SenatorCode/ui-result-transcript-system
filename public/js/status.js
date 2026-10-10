export const TRANSCRIPT_STATUS = Object.freeze({
  pending_payment: { label: 'Pending payment', tone: 'warning', detail: 'Payment is still needed.' },
  payment_processing: { label: 'Payment processing', tone: 'process', detail: 'We are confirming your payment.' },
  paid: { label: 'Paid', tone: 'info', detail: 'Payment is confirmed.' },
  processing: { label: 'Processing', tone: 'process', detail: 'Registry is preparing your transcript.' },
  ready: { label: 'Ready', tone: 'success', detail: 'Your personal copy is ready.' },
  sent: { label: 'Sent', tone: 'success', detail: 'Your transcript has been sent.' },
  failed: { label: 'Failed', tone: 'error', detail: 'This request needs attention.' },
  cancelled: { label: 'Cancelled', tone: 'neutral', detail: 'This request was cancelled.' },
  rejected: { label: 'Rejected', tone: 'error', detail: 'Registry could not complete this request.' },
  expired: { label: 'Expired', tone: 'neutral', detail: 'This unpaid request has expired.' }
});

export const GRADING_SCALE = Object.freeze([
  { grade: 'A', points: 5, meaning: 'Excellent' }, { grade: 'B', points: 4, meaning: 'Very good' },
  { grade: 'C', points: 3, meaning: 'Good' }, { grade: 'D', points: 2, meaning: 'Pass' },
  { grade: 'E', points: 1, meaning: 'Pass' }, { grade: 'F', points: 0, meaning: 'Fail' }
]);
