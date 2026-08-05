import { useEffect, useRef, useState } from "react";
import InterpretUpload from "./InterpretUpload";
import InterpretProcessing from "./InterpretProcessing";
import InterpretSummary from "./InterpretSummary";
import InterpretStudyReview from "./InterpretStudyReview";
import {
	PROCESSING_STEPS,
	buildInterpretSummary,
	interpretDocument,
} from "./interpretApi";

/**
 * FASE 2 — Interpret stage orchestrator (upload → process → summary → review).
 * Reuses POST /interpret. No matching UI.
 */
export default function InterpretStage({
	onSessionReady,
	onInterpretComplete,
}) {
	const [view, setView] = useState("upload"); // upload | processing | summary | review
	const [processIndex, setProcessIndex] = useState(0);
	const [error, setError] = useState(null);
	const [summary, setSummary] = useState(null);
	const [payload, setPayload] = useState(null);
	const [previewUrl, setPreviewUrl] = useState(null);
	const [fileName, setFileName] = useState(null);
	const [startedAt, setStartedAt] = useState(null);
	const fileRef = useRef(null);
	const timersRef = useRef([]);
	const previewUrlRef = useRef(null);

	const clearTimers = () => {
		timersRef.current.forEach(clearTimeout);
		timersRef.current = [];
	};

	const setPreview = (url) => {
		if (
			previewUrlRef.current?.startsWith("blob:") &&
			previewUrlRef.current !== url
		) {
			URL.revokeObjectURL(previewUrlRef.current);
		}
		previewUrlRef.current = url;
		setPreviewUrl(url);
	};

	useEffect(
		() => () => {
			clearTimers();
			if (previewUrlRef.current?.startsWith("blob:")) {
				URL.revokeObjectURL(previewUrlRef.current);
			}
		},
		[],
	);

	const runInterpretation = async (file, localPreview) => {
		clearTimers();
		fileRef.current = file;
		setPreview(localPreview);
		setFileName(file?.name || null);
		setError(null);
		setSummary(null);
		setPayload(null);
		setProcessIndex(0);
		setStartedAt(Date.now());
		setView("processing");

		// Stagger UI states while the real request runs
		PROCESSING_STEPS.forEach((_, i) => {
			if (i === 0) return;
			const t = setTimeout(() => {
				setProcessIndex((prev) => Math.max(prev, i));
			}, i * 1100);
			timersRef.current.push(t);
		});

		const result = await interpretDocument(file);
		clearTimers();

		if (!result.ok) {
			setProcessIndex(Math.min(2, PROCESSING_STEPS.length - 1));
			setError(result.message);
			return;
		}

		// Illuminate remaining steps quickly, then success hero
		setProcessIndex(PROCESSING_STEPS.length - 1);
		const finishSteps = setTimeout(() => {
			setProcessIndex(PROCESSING_STEPS.length);
		}, 280);
		timersRef.current.push(finishSteps);

		const nextSummary = buildInterpretSummary(result.data);
		setPayload(result.data);
		setSummary(nextSummary);
		onSessionReady?.(result.data, nextSummary, localPreview);

		const t = setTimeout(() => setView("summary"), 720);
		timersRef.current.push(t);
	};

	const handleRetry = () => {
		if (fileRef.current) {
			runInterpretation(fileRef.current, previewUrl);
		}
	};

	const handleChangeFile = () => {
		clearTimers();
		setError(null);
		setStartedAt(null);
		setView("upload");
		setProcessIndex(0);
	};

	if (view === "upload") {
		return (
			<InterpretUpload
				onFileReady={(file, url) => runInterpretation(file, url)}
			/>
		);
	}

	if (view === "processing") {
		return (
			<InterpretProcessing
				activeIndex={
					error
						? processIndex
						: Math.min(processIndex, PROCESSING_STEPS.length - 1)
				}
				error={error}
				previewUrl={previewUrl}
				fileName={fileName}
				startedAt={startedAt}
				onRetry={handleRetry}
				onChangeFile={handleChangeFile}
			/>
		);
	}

	if (view === "summary") {
		return (
			<InterpretSummary
				summary={summary}
				previewUrl={previewUrl}
				fileName={fileName}
				onReviewStudies={() => setView("review")}
			/>
		);
	}

	return (
		<InterpretStudyReview
			studies={summary?.studies || []}
			previewUrl={previewUrl}
			fileName={fileName}
			onBackToSummary={() => setView("summary")}
			onDone={() => {
				onInterpretComplete?.(payload, summary);
			}}
		/>
	);
}
