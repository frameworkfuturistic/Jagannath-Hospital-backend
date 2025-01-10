<?php

namespace App\Http\Controllers\API\V1;

use Illuminate\Http\Request;
use App\Http\Controllers\Controller;
use App\Services\RazorpayService;
use App\Models\Payment;
use App\Models\MrMaster;
use App\Models\OpdOnlineAppointment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;


class PaymentController extends Controller
{
    protected $razorpayService;

    public function __construct(RazorpayService $razorpayService)
    {
        $this->razorpayService = $razorpayService;
    }

    // Method to initiate payment
    public function createPayment(Request $request)
    {
        // Validate incoming request data
        $validated = $request->validate([
            'OPDOnlineAppointmentID' => 'required|integer|exists:opd_onlineappointments,OPDOnlineAppointmentID',
            'AmountPaid' => 'required|numeric', // Ensure this is a valid number
            'PaymentMode' => 'required|string|max:50', // Validate payment mode
            'CreatedBy' => 'nullable|integer', // Optional creator ID
        ]);

        DB::beginTransaction(); // Start the transaction

        try {
            // Set CreatedBy to null if not provided in the request
            $createdBy = isset($validated['CreatedBy']) ? $validated['CreatedBy'] : null;

            // Create a new payment record
            $payment = Payment::create([
                'OPDOnlineAppointmentID' => $validated['OPDOnlineAppointmentID'],
                'PaymentDate' => now(), // Set current date/time
                'PaymentMode' => $validated['PaymentMode'],
                'PaymentStatus' => 'Pending', // Initial status
                'AmountPaid' => $validated['AmountPaid'],
                'TransactionID' => null, // Will be updated after Razorpay order creation
                'CreatedBy' => $createdBy, // Use the extracted or default value
            ]);

            // Prepare order data for Razorpay
            $orderData = [
                'amount' => $validated['AmountPaid'], // Convert to paise
                'currency' => 'INR',
                'receipt' => $payment->PaymentID, // Associate the receipt with payment ID
            ];

            // Create order with Razorpay service
            $razorpayOrder = $this->razorpayService->createOrder($orderData);

            // Update payment with Razorpay transaction ID
            $payment->TransactionID = $razorpayOrder->id;
            $payment->save(); // Save the payment record

            DB::commit(); // Commit the transaction

            // Return successful response
            return response()->json([
                'message' => 'Payment initiated successfully.',
                'payment_id' => $payment->PaymentID,
                'order_id' => $razorpayOrder->id,
            ], 201);
        } catch (\Exception $e) {
            Log::error('Payment initiation failed: ' . $e->getMessage(), [
                'request' => $request->all(), // Log the request data
                'stack_trace' => $e->getTraceAsString() // Log the stack trace
            ]);
            DB::rollBack(); // Rollback the transaction on error
            return response()->json([
                'message' => 'Payment initiation failed: ' . $e->getMessage(),
            ], 500);
        }
    }



    // Handle Razorpay payment callback
    // public function handlePaymentCallback(Request $request)
    // {
    //     $webhookBody = $request->getContent(); // Raw request body
    //     $webhookSignature = $request->header('X-Razorpay-Signature'); // Razorpay signature header

    //     // Check if the Razorpay signature is missing
    //     if (is_null($webhookSignature)) {
    //         \Log::error('Missing Razorpay signature.', [
    //             'headers' => $request->headers->all(), // Log all headers for debugging
    //             'webhookBody' => $webhookBody, // Log the raw webhook payload for analysis
    //         ]);

    //         return response()->json(['message' => 'Missing Razorpay signature'], 400);
    //     }

    //     $webhookSecret = config('razorpay.webhook_secret'); // Fetch webhook secret from config

    //     // Verify the webhook signature
    //     $isValidSignature = $this->razorpayService->verifyWebhookSignature($webhookBody, $webhookSignature, $webhookSecret);

    //     if ($isValidSignature) {
    //         $payload = json_decode($webhookBody, true);
    //         $paymentId = $payload['payload']['payment']['entity']['id'];

    //         // Find the payment record associated with this transaction
    //         $payment = Payment::where('TransactionID', $paymentId)->first();
    //         if ($payment && $payment->PaymentStatus !== 'Completed') {
    //             $payment->PaymentStatus = 'Completed'; // Mark payment as completed
    //             $payment->PaymentDate = now(); // Update payment date
    //             $payment->save();
    //         }

    //         return response()->json(['message' => 'Webhook processed successfully'], 200);
    //     }

    //     // Return invalid signature response
    //     return response()->json(['message' => 'Invalid signature'], 400);
    // }



    // Method to reconcile payment (for internal use, if needed)
    public function reconcilePayment($paymentId)
    {
        // Example method; implement reconciliation logic as needed
        $result = $this->razorpayService->fetchPaymentDetails($paymentId);
        return response()->json($result);
    }

    // Method to get payment history
    public function getPaymentHistory(Request $request)
    {
        // Example method; implement payment history retrieval logic as needed
        $payments = Payment::all(); // Replace with appropriate logic if needed
        return response()->json($payments);
    }

    // Method to get payment details by ID
    public function getPaymentById(Request $request)
    {
        $paymentId = $request->input('payment_id');

        if (!$paymentId || !is_numeric($paymentId)) {
            return response()->json(['message' => 'Invalid payment ID'], 400);
        }

        $payment = Payment::find($paymentId);

        if (!$payment) {
            return response()->json(['message' => 'Payment not found'], 404);
        }

        return response()->json($payment);
    }


    public function handlePaymentCallback(Request $request)
    {
        // Step 1: Get raw webhook data and signature
        $webhookBody = $request->getContent(); // Raw request body
        $webhookSignature = $request->header('X-Razorpay-Signature'); // Razorpay signature header

        // Step 2: Validate signature
        if (is_null($webhookSignature)) {
            Log::error('Missing Razorpay signature.', [
                'headers' => $request->headers->all(),
                'webhookBody' => $webhookBody,
            ]);
            return response()->json(['message' => 'Missing Razorpay signature'], 400);
        }

        $webhookSecret = config('razorpay.webhook_secret'); // Razorpay webhook secret from config

        // Verify the webhook signature
        $isValidSignature = $this->verifyWebhookSignature($webhookBody, $webhookSignature, $webhookSecret);

        // Step 3: Handle invalid signature
        if (!$isValidSignature) {
            return response()->json(['message' => 'Invalid signature'], 400);
        }

        // Step 4: Process the payment if the signature is valid
        $payload = json_decode($webhookBody, true);
        $paymentId = $payload['payload']['payment']['entity']['id'];

        // Extract payment details
        $amount = $payload['payload']['payment']['entity']['amount'] ?? 1000.00;
        $mrNo = $payload['payload']['payment']['entity']['MRNo'] ?? null;
        $patientName = $payload['payload']['payment']['entity']['patientname'] ?? 'Unknown';
        $appointmentId = $payload['payload']['payment']['entity']['appointment_id'];

        // Step 5: Check the payment status
        $payment = Payment::where('TransactionID', $paymentId)->first();

        // If payment status is not 'Completed', update it to 'Completed'
        if ($payment) {
            try {
                $payment->PaymentStatus = 'Completed';
                $payment->PaymentDate = now(); // Set the current date and time
                $payment->save();
                return $this->createMRNoAndRecords($mrNo, $patientName, $amount, $appointmentId, $paymentId);

                Log::info('Payment marked as completed for PaymentID: ' . $paymentId);
            } catch (\Exception $e) {
                Log::error('Error updating payment status: ' . $e->getMessage());
                return response()->json(['message' => 'Error updating payment status'], 500);
            }
        }
    }

    // Helper function to verify Razorpay webhook signature
    protected function verifyWebhookSignature($body, $signature, $secret)
    {
        $generatedSignature = hash_hmac('sha256', $body, $secret);
        return hash_equals($generatedSignature, $signature);
    }

    // Helper function to create MRNo and necessary records after payment
    protected function createMRNoAndRecords($mrNo, $patientName, $amount, $appointmentId, $paymentId)
    {
        DB::beginTransaction();
        try {
            // Step 7: If MRNo is not provided, generate a new one
            if (!$mrNo) {
                $mrNo = $this->generateMRNo(); // Call the generateMRNo function
                Log::info('Generated MRNo: ' . $mrNo);
            }

            // Step 8: Check if MRNo already exists in the database
            $mrRecord = DB::table('mr_master')->where('MRNo', $mrNo)->first();

            if (!$mrRecord) {
                // Create a new MRNo record in the database
                MrMaster::create([
                    'MRNo' => $mrNo,
                    'MRDate' => now(),
                    'PatientName' => $patientName,
                ]);
                Log::info('New MRNo record created successfully: ' . $mrNo);
            }

            // Step 9: Create records in opd_registrations
            $registrationId = DB::table('opd_registrations')->insertGetId([
                'MRNo' => $mrNo,
                'RegistrationDate' => now(),
                'ConsultationDate' => now(),
                'RegistrationFee' => 100,
                'Amount' => $amount,
                'PaymentMode' => 'Online',
                'CreatedBy' => auth()->id() ?? 1,
                'CreatedOn' => now(),
            ]);

            Log::info('New record created in opd_registrations for MRNo: ' . $mrNo);

            // Step 10: Create records in opd_consultations
            DB::table('opd_consultations')->insert([
                'RegistrationID' => $registrationId,
                'ConsultationDate' => now(),
                'ConsultedAt' => now(),
                'PatientName' => $patientName,
                'CreatedBy' => auth()->id() ?? 1,
                'CreatedOn' => now(),
            ]);

            Log::info('New record created in opd_consultations for RegistrationID: ' . $registrationId);
        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Error creating records in opd_registrations/opd_consultations: ' . $e->getMessage());
            return response()->json(['message' => 'Error creating records'], 500);
        }
    }

    // Method to generate MRNo
    public function generateMRNo()
    {
        try {
            // Retrieve the MR counter from the database
            $mrParameter = DB::table('mr_parameter')->where('ID', 1)->first();

            if (!$mrParameter) {
                Log::error("MR Parameter not found. ID: 1");
                throw new \Exception('MR Parameter not found.');
            }

            // Get the current MR counter, default to 0 if not set
            $mrCounter = $mrParameter->MRCounter ?? 0;

            // Increment the counter
            DB::table('mr_parameter')->where('ID', 1)->update(['MRCounter' => $mrCounter + 1]);

            // Generate MRNo with year, month, and counter
            $currentYear = now()->year % 100; // Last two digits of the year
            $currentMonth = str_pad(now()->month, 2, '0', STR_PAD_LEFT); // Two-digit month
            $mrNo = $currentYear . $currentMonth . str_pad($mrCounter, 5, '0', STR_PAD_LEFT);

            // Append checksum
            $checksum = array_sum(str_split($mrNo)) % 9;
            $mrNo .= $checksum;

            Log::info("Generated MRNo: " . $mrNo);
            return $mrNo;
        } catch (\Exception $e) {
            Log::error("Failed to generate MRNo: " . $e->getMessage());
            throw new \Exception('Failed to generate MRNo: ' . $e->getMessage());
        }
    }
}



    // public function handlePaymentCallback(Request $request)
    // {
    //     // Retrieve webhook payload and signature
    //     $webhookBody = $request->getContent();
    //     $webhookSignature = $request->header('X-Razorpay-Signature');

    //     // Simulate Razorpay webhook signature verification (skip actual verification for local testing)
    //     $isValidSignature = true;

    //     if (!$isValidSignature) {
    //         return response()->json(['message' => 'Invalid signature'], 400);
    //     }

    //     // Decode the webhook payload
    //     $payload = json_decode($webhookBody, true);

    //     // Log the payload for debugging purposes
    //     Log::info('Received Webhook Payload: ', $payload);

    //     // Extract payment details from the payload
    //     $paymentId = $payload['payment_id'] ?? 'DUMMY_PAYMENT_ID';
    //     $paymode = $payload['paymentmode'] ?? 'Online';
    //     $amount = $payload['amount'] ?? 1000.00;
    //     $mrNo = $payload['MRNo'] ?? null;
    //     $patientName = $payload['patientname'] ?? 'Unknown';
    //     $appointmentId = $payload['appointment_id'];

    //     // If MRNo is not provided in the payload, generate one
    //     if (!$mrNo) {
    //         try {
    //             $mrNo = $this->generateMRNo();
    //             Log::info('Generated MRNo: ' . $mrNo);
    //         } catch (\Exception $e) {
    //             return response()->json(['message' => 'Failed to generate MRNo: ' . $e->getMessage()], 500);
    //         }
    //     }

    //     // Check if the MRNo exists in the database
    //     $mrRecord = DB::table('mr_master')->where('MRNo', $mrNo)->first();

    //     if (!$mrRecord) {
    //         try {
    //             Log::info('Attempting to create MRNo: ' . $mrNo);
    //             MrMaster::create([
    //                 'MRNo' => $mrNo,
    //                 'MRDate' => now(),
    //                 'PatientName' => $patientName,
    //             ]);
    //             Log::info('New MRNo record created successfully: ' . $mrNo);
    //         } catch (\Exception $e) {
    //             Log::error('Error creating MRNo: ' . $e->getMessage());
    //             return response()->json(['message' => 'Error creating MRNo: ' . $e->getMessage()], 500);
    //         }
    //     }

    //     // Insert a new record into opd_registrations
    //     try {
    //         Log::info('Attempting to create new opd_registrations for MRNo: ' . $mrNo);
    //         $registrationId = DB::table('opd_registrations')->insertGetId([
    //             'MRNo' => $mrNo,
    //             'RegistrationDate' => now(),
    //             'ConsultationDate' => now(),
    //             'RegistrationFee' => 100,
    //             'Amount' => $amount,
    //             'PaymentMode' => $paymode,
    //             'CreatedBy' => auth()->id() ?? 1,
    //             'CreatedOn' => now(),
    //         ]);
    //         Log::info('New record created in opd_registrations for MRNo: ' . $mrNo);

    //         // Insert new record into opd_consultations
    //         DB::table('opd_consultations')->insert([
    //             'RegistrationID' => $registrationId,
    //             'ConsultationDate' => now(),
    //             'ConsultedAt' => now(),
    //             'PatientName' => $patientName,
    //             'CreatedBy' => auth()->id() ?? 1,
    //             'CreatedOn' => now(),
    //         ]);

    //         Log::info('New record created in opd_consultations for RegistrationID: ' . $registrationId);
    //     } catch (\Exception $e) {
    //         Log::error('Error creating records in opd_registrations/opd_consultations: ' . $e->getMessage());
    //         return response()->json(['message' => 'Error creating records: ' . $e->getMessage()], 500);
    //     }

    //     // Always insert a new payment record, no matter if it already exists
    //     try {
    //         DB::table('payments')->insert([
    //             'payment_id' => $paymentId,
    //             'appointment_id' => $appointmentId,
    //             'amount' => $amount,
    //             'status' => 'captured',
    //             'TransactionID' => $payload['transaction_id'] ?? null,
    //             'created_at' => now(),
    //             'updated_at' => now(),
    //         ]);
    //         Log::info('New payment record inserted successfully for PaymentID: ' . $paymentId);
    //     } catch (\Exception $e) {
    //         Log::error('Error inserting payment record: ' . $e->getMessage());
    //         return response()->json(['message' => 'Error inserting payment record: ' . $e->getMessage()], 500);
    //     }

    //     return response()->json(['message' => 'Payment callback processed successfully']);
    // }

    // private function generateMRNo()
    // {
    //     try {
    //         // Retrieve the MR counter from the database
    //         $mrParameter = DB::table('mr_parameter')->where('ID', 1)->first();

    //         if (!$mrParameter) {
    //             Log::error("MR Parameter not found. ID: 1");
    //             throw new \Exception('MR Parameter not found.');
    //         }

    //         $mrCounter = $mrParameter->MRCounter ?? 0;

    //         // Increment the counter
    //         DB::table('mr_parameter')->where('ID', 1)->update(['MRCounter' => $mrCounter + 1]);

    //         // Generate MRNo with year, month, and counter
    //         $currentYear = now()->year % 100; // Last two digits of the year
    //         $currentMonth = str_pad(now()->month, 2, '0', STR_PAD_LEFT); // Two-digit month
    //         $mrNo = $currentYear . $currentMonth . str_pad($mrCounter, 5, '0', STR_PAD_LEFT);

    //         // Append checksum
    //         $checksum = array_sum(str_split($mrNo)) % 9;
    //         $mrNo .= $checksum;

    //         Log::info("Generated MRNo: " . $mrNo);
    //         return $mrNo;
    //     } catch (\Exception $e) {
    //         Log::error("Failed to generate MRNo: " . $e->getMessage());
    //         throw new \Exception('Failed to generate MRNo: ' . $e->getMessage());
    //     }
    // }
