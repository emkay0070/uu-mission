import { cn } from '@/components/ui';
import {
  CONTRIBUTION_STATE_META,
  REQUEST_STATUS_META,
  type ContributionState,
  type RequestStatus,
} from './resourceLifecycle';

export function ContributionStatusBadge({
  state,
  className,
}: {
  state: ContributionState;
  className?: string;
}) {
  const meta = CONTRIBUTION_STATE_META[state];
  return <span className={cn('badge', meta.className, className)}>{meta.label}</span>;
}

export function RequestStatusBadge({
  status,
  className,
}: {
  status: RequestStatus;
  className?: string;
}) {
  const meta = REQUEST_STATUS_META[status];
  return <span className={cn('badge', meta.className, className)}>{meta.label}</span>;
}
