import { useState } from "react";
import { Button } from "@/Components/Catalyst/button";
import {
	Dialog,
	DialogTitle,
	DialogBody,
	DialogActions,
} from "@/Components/Catalyst/dialog";
import {
	Dropdown,
	DropdownButton,
	DropdownDivider,
	DropdownItem,
	DropdownMenu,
} from "@/Components/Catalyst/dropdown";
import { Code } from "@/Components/Catalyst/text";
import { Badge } from "@/Components/Catalyst/badge";
import {
	UserCircleIcon,
	CommandLineIcon,
	CheckCircleIcon,
	PlusCircleIcon,
	ChatBubbleLeftRightIcon,
} from "@heroicons/react/16/solid";
import DevAssistanceComposer from "@/Components/DevAssistance/DevAssistanceComposer";
import DevAssistanceCommentCard from "@/Components/DevAssistance/DevAssistanceCommentCard";
import { CreateDevAssistanceDialogContent } from "@/Components/DevAssistance/DevAssistanceButton";
import { Text } from "@/Components/Catalyst/text";

const BUTTON_TEXT = "Asistencia técnica";
const DIALOG_TITLE = "Asistencia técnica";
const COMMENTS_LABEL = "Comentarios";
const CLOSE_BUTTON_TEXT = "Cerrar";
const NEW_REQUEST_TEXT = "Nueva solicitud";
const REOPEN_DESCRIPTION = "Explica por qué estás reabriendo esta solicitud";

export default function DevAssistanceDropdown({
	requests = [],
	storeRoute,
	resolveRouteName,
	unresolveRouteName,
	routeParams,
	className = "",
}) {
	const [isDialogOpen, setIsDialogOpen] = useState(false);
	const [selectedRequestId, setSelectedRequestId] = useState(null);

	const unresolvedRequests = requests.filter((r) => !r.resolved_at);
	const resolvedRequests = requests.filter((r) => r.resolved_at);
	const hasUnresolvedRequest = unresolvedRequests.length > 0;

	const selectedRequest = requests.find((r) => r.id === selectedRequestId);

	const handleButtonClick = () => {
		if (hasUnresolvedRequest) {
			setSelectedRequestId(unresolvedRequests[0].id);
			setIsDialogOpen(true);
		}
	};

	const handleViewRequest = (request) => {
		setSelectedRequestId(request.id);
		setIsDialogOpen(true);
	};

	const handleCloseDialog = () => {
		setIsDialogOpen(false);
		setSelectedRequestId(null);
	};

	if (!hasUnresolvedRequest) {
		return (
			<>
				<Dropdown>
					<DropdownButton outline className={className}>
						<CommandLineIcon />
						{BUTTON_TEXT}
						<Badge color="slate" className="ml-2">
							{resolvedRequests.length}
						</Badge>
					</DropdownButton>
					<DropdownMenu>
						{resolvedRequests.map((request) => (
							<DropdownItem
								key={request.id}
								onClick={() => handleViewRequest(request)}
							>
								<CheckCircleIcon />
								<div className="flex flex-col">
									<span className="font-medium">
										{request.administrator.user.name}
									</span>
									<span className="text-xs">
										{request.formatted_requested_at}
									</span>
								</div>
							</DropdownItem>
						))}
						<DropdownDivider />
						<DropdownItem
							onClick={() => {
								setSelectedRequestId(null);
								setIsDialogOpen(true);
							}}
						>
							<PlusCircleIcon />
							{NEW_REQUEST_TEXT}
						</DropdownItem>
					</DropdownMenu>
				</Dropdown>

				<Dialog
					open={isDialogOpen && !selectedRequest}
					onClose={handleCloseDialog}
				>
					<CreateDevAssistanceDialogContent
						storeRoute={storeRoute}
						onSuccess={handleCloseDialog}
					/>
				</Dialog>

				{selectedRequest && (
					<ViewRequestDialog
						isOpen={isDialogOpen && !!selectedRequest}
						onClose={handleCloseDialog}
						request={selectedRequest}
						resolveRouteName={resolveRouteName}
						unresolveRouteName={unresolveRouteName}
						routeParams={routeParams}
					/>
				)}
			</>
		);
	}

	return (
		<>
			<Button outline onClick={handleButtonClick} className={className}>
				<CommandLineIcon className="animate-pulse fill-famedic-light" />
				{BUTTON_TEXT}
			</Button>

			{selectedRequest && (
				<ViewRequestDialog
					isOpen={isDialogOpen && !!selectedRequest}
					onClose={handleCloseDialog}
					request={selectedRequest}
					resolveRouteName={resolveRouteName}
					unresolveRouteName={unresolveRouteName}
					routeParams={routeParams}
				/>
			)}
		</>
	);
}

function ViewRequestDialog({
	isOpen,
	onClose,
	request: devAssistanceRequest,
	resolveRouteName,
	unresolveRouteName,
	routeParams,
}) {
	const composerRoute = devAssistanceRequest.resolved_at
		? route(unresolveRouteName, {
				...routeParams,
				dev_assistance_request: devAssistanceRequest.id,
			})
		: route(resolveRouteName, {
				...routeParams,
				dev_assistance_request: devAssistanceRequest.id,
			});

	const composer = devAssistanceRequest.resolved_at ? (
		<DevAssistanceComposer
			route={composerRoute}
			description={REOPEN_DESCRIPTION}
			asCard={true}
		/>
	) : (
		<DevAssistanceComposer
			route={composerRoute}
			showMarkResolved={true}
			asCard={true}
		/>
	);

	return (
		<Dialog open={isOpen} onClose={onClose} size="2xl">
			<div className="flex justify-between">
				<Badge color="slate">
					<UserCircleIcon className="size-4 fill-slate-400 dark:fill-slate-500" />
					{devAssistanceRequest.administrator.user.name}
				</Badge>
				<Code>{devAssistanceRequest.formatted_requested_at}</Code>
			</div>
			<DialogTitle className="mt-6">{DIALOG_TITLE}</DialogTitle>
			{devAssistanceRequest.resolved_at && (
				<Badge className="mt-2">
					<CheckCircleIcon className="size-4" />
					Resuelta el {devAssistanceRequest.formatted_resolved_at}
				</Badge>
			)}
			<DialogBody className="space-y-6">
				<div>
					<div className="flex items-center gap-2 text-sm">
						<ChatBubbleLeftRightIcon className="size-5 text-slate-500 dark:text-slate-500" />
						<Text className="!text-lg">{COMMENTS_LABEL}</Text>
						{devAssistanceRequest.comments.length > 0 && (
							<Badge color="slate" className="text-xs">
								{devAssistanceRequest.comments.length}
							</Badge>
						)}
					</div>
					<ul role="list" className="mt-4 space-y-4">
						{devAssistanceRequest.comments.map((comment) => (
							<li
								key={comment.id}
								className="relative flex gap-x-3"
							>
								<TimelineConnector isLast={false} />
								<TimelineDot />
								<DevAssistanceCommentCard comment={comment} />
							</li>
						))}

						<li className="relative flex gap-x-3">
							<TimelineConnector isLast={true} />
							<TimelineDot />
							<div className="flex-1">{composer}</div>
						</li>
					</ul>
				</div>
			</DialogBody>
			<DialogActions>
				<Button plain onClick={onClose} type="button">
					{CLOSE_BUTTON_TEXT}
				</Button>
			</DialogActions>
		</Dialog>
	);
}

function TimelineConnector({ isLast }) {
	return (
		<div
			className={`absolute left-0 top-0 flex w-5 justify-center ${
				isLast ? "h-5" : "-bottom-4"
			}`}
		>
			<div className="w-px bg-slate-200 dark:bg-slate-700" />
		</div>
	);
}

function TimelineDot() {
	return (
		<div className="relative flex size-5 flex-none items-center justify-center bg-white dark:bg-slate-900">
			<div className="size-1 rounded-full bg-slate-400 ring-1 ring-slate-300 dark:bg-slate-500 dark:ring-slate-600" />
		</div>
	);
}
